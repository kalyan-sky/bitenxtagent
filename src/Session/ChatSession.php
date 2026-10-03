<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Session;

/**
 * One conversation, stored server-side. The customer's identity lives here,
 * set from a verified Magento token, never from anything typed in the chat.
 * The token itself is not stored, only its hash.
 *
 * Two histories are kept on purpose:
 * - $messages: what Claude sees (wire format, append-only, includes tool calls);
 * - $transcript: exactly what was shown to the customer, after the output
 *   guard. Chat history in the widget and the staff view is built only from
 *   this, so redacted values and blocked replies can never reappear.
 */
final class ChatSession
{
    /**
     * @param list<array<string, mixed>> $messages Claude conversation history, wire format, append-only
     * @param list<string> $knownOrderNumbers order numbers already confirmed to belong to this customer
     * @param list<string> $safeValues identifiers (order/tracking numbers, SKUs) that came from this
     *                                 customer's own data and may be repeated back to them
     * @param list<array{role: string, text: string, at: int}> $transcript what the customer saw
     */
    public function __construct(
        public readonly string $id,
        public string $tokenHash = '',
        public string $customerId = '',
        public string $customerFirstname = '',
        public string $customerEmail = '',
        public array $messages = [],
        public array $knownOrderNumbers = [],
        public array $safeValues = [],
        public int $userTurns = 0,
        public int $failedOrderLookups = 0,
        public bool $escalated = false,
        public int $createdAt = 0,
        public int $updatedAt = 0,
        public array $transcript = [],
        public string $title = '',
    ) {
    }

    /**
     * A brand-new session with a random ID. Unknown, expired or malformed IDs
     * always lead here: a client can never choose the ID of its session.
     */
    public static function start(): self
    {
        return new self(id: bin2hex(random_bytes(24)), createdAt: time());
    }

    public static function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9]{48}$/', $id);
    }

    public function isAuthenticated(): bool
    {
        return $this->customerId !== '' || $this->customerEmail !== '';
    }

    /**
     * Stable identity of the customer who owns this conversation. A Magento
     * customer can have many tokens over time; the conversation follows the
     * customer, not the token.
     */
    public function ownerKey(): string
    {
        return self::ownerKeyFor($this->customerId, $this->customerEmail);
    }

    public static function ownerKeyFor(string $customerId, string $email): string
    {
        if ($customerId !== '') {
            return 'id:' . $customerId;
        }

        return $email !== '' ? 'email:' . mb_strtolower($email) : '';
    }

    public function addTranscript(string $role, string $text): void
    {
        $this->transcript[] = ['role' => $role, 'text' => $text, 'at' => time()];
        if ($this->title === '' && $role === 'user') {
            $title = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
            $this->title = mb_strlen($title) > 60 ? mb_substr($title, 0, 57) . '…' : $title;
        }
    }

    /**
     * The listing view of a conversation: what the past-chats list and staff
     * list show, and what the Firestore store keeps as queryable fields.
     *
     * @return array{id: string, title: string, updatedAt: int, messageCount: int, escalated: bool,
     *               ownerKey: string, customerEmail: string}
     */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'updatedAt' => $this->updatedAt,
            'messageCount' => count($this->transcript),
            'escalated' => $this->escalated,
            'ownerKey' => $this->ownerKey(),
            'customerEmail' => mb_strtolower($this->customerEmail),
        ];
    }

    public function rememberOrder(string $orderNumber): void
    {
        if (!in_array($orderNumber, $this->knownOrderNumbers, true)) {
            $this->knownOrderNumbers[] = $orderNumber;
        }
    }

    public function addSafeValue(string $value): void
    {
        $value = trim($value);
        if ($value !== '' && !in_array($value, $this->safeValues, true)) {
            $this->safeValues[] = $value;
            $this->safeValues = array_slice($this->safeValues, -200);
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            tokenHash: (string) ($data['tokenHash'] ?? ''),
            customerId: (string) ($data['customerId'] ?? ''),
            customerFirstname: (string) ($data['customerFirstname'] ?? ''),
            customerEmail: (string) ($data['customerEmail'] ?? ''),
            messages: self::restoreEmptyObjects($data['messages'] ?? []),
            knownOrderNumbers: array_values($data['knownOrderNumbers'] ?? []),
            safeValues: array_values($data['safeValues'] ?? []),
            userTurns: (int) ($data['userTurns'] ?? 0),
            failedOrderLookups: (int) ($data['failedOrderLookups'] ?? 0),
            escalated: (bool) ($data['escalated'] ?? false),
            createdAt: (int) ($data['createdAt'] ?? 0),
            updatedAt: (int) ($data['updatedAt'] ?? 0),
            transcript: array_values($data['transcript'] ?? []),
            title: (string) ($data['title'] ?? ''),
        );
    }

    /**
     * json_decode(..., true) turns `"input": {}` into `[]`, which would be
     * re-sent to the API as a JSON array. Put the empty object back so the
     * replayed history is byte-for-byte what Claude produced.
     *
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    public static function restoreEmptyObjects(array $messages): array
    {
        foreach ($messages as &$message) {
            if (!is_array($message['content'] ?? null)) {
                continue;
            }
            foreach ($message['content'] as &$block) {
                if (($block['type'] ?? '') === 'tool_use' && ($block['input'] ?? null) === []) {
                    $block['input'] = new \stdClass();
                }
            }
        }

        return $messages;
    }
}

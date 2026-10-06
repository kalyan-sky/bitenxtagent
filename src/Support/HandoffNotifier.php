<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Support;

/**
 * Sends "a human should pick this up" requests to the support team: by email
 * (HANDOFF_EMAIL_TO over SMTP) and/or a webhook (Slack, Teams, Google Chat,
 * helpdesk). Always recorded in the logs too. Staff-facing, so it includes
 * the customer's account email and the recent conversation.
 */
class HandoffNotifier
{
    public function __construct(
        private readonly string $file,
        private readonly string $webhookUrl = '',
        private readonly ?HandoffMailer $mailer = null,
        private readonly ?Logger $logger = null,
    ) {
    }

    /** Whether hand-overs can reach a person at all (email or webhook configured). */
    public function canReachTeam(): bool
    {
        return $this->webhookUrl !== '' || ($this->mailer?->isConfigured() ?? false);
    }

    /**
     * @param array<string, mixed> $handoff
     * @return bool true if the email or the webhook was delivered
     */
    public function notify(array $handoff): bool
    {
        $handoff['ts'] = date(DATE_ATOM);
        $conversation = $handoff['conversation'] ?? [];
        unset($handoff['conversation']); // the log keeps the request, not the chat text
        Logger::append($this->file, (string) json_encode(
            ['severity' => 'WARNING', 'message' => 'handoff', 'event' => 'handoff'] + $handoff,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        $delivered = false;
        if ($this->mailer?->isConfigured()) {
            $delivered = $this->mailer->send(self::subject($handoff), self::body($handoff, $conversation), (string) ($handoff['customer_email'] ?? ''));
        }
        if ($this->webhookUrl !== '') {
            $delivered = $this->postWebhook(self::body($handoff, [])) || $delivered;
        }
        if (!$delivered) {
            $this->logger?->log('handoff_not_delivered', ['session' => $handoff['session_id'] ?? '', 'channels_configured' => $this->canReachTeam()]);
        }

        return $delivered;
    }

    /** @param array<string, mixed> $handoff */
    private static function subject(array $handoff): string
    {
        return sprintf(
            '[BiteNXT chat]%s %s: %s%s',
            ($handoff['urgency'] ?? '') === 'high' ? ' URGENT' : '',
            str_replace('_', ' ', ucfirst((string) ($handoff['reason'] ?? 'other'))),
            $handoff['customer_email'] ?? 'unknown customer',
            !empty($handoff['order_number']) ? ' (order ' . $handoff['order_number'] . ')' : '',
        );
    }

    /**
     * @param array<string, mixed> $handoff
     * @param list<array{role: string, text: string, at?: int}> $conversation
     */
    private static function body(array $handoff, array $conversation): string
    {
        $lines = [
            'A customer asked the BiteNXT support chat for help from the team.',
            '',
            'Customer: ' . trim(($handoff['customer_name'] ?? '') . ' <' . ($handoff['customer_email'] ?? 'unknown') . '>'),
            'Customer ID: ' . ($handoff['customer_id'] ?? '-'),
            'Reason: ' . str_replace('_', ' ', (string) ($handoff['reason'] ?? 'other')),
            'Urgency: ' . ($handoff['urgency'] ?? 'normal'),
            'Order: ' . (($handoff['order_number'] ?? '') !== '' ? $handoff['order_number'] : '-'),
            'Summary: ' . ($handoff['summary'] ?? ''),
            'Chat session: ' . ($handoff['session_id'] ?? '') . ' (' . ($handoff['ts'] ?? '') . ')',
        ];
        if ($conversation !== []) {
            $lines[] = '';
            $lines[] = 'Recent conversation:';
            foreach ($conversation as $entry) {
                $who = ($entry['role'] ?? '') === 'user' ? 'Customer' : 'Bot';
                $lines[] = "{$who}: " . str_replace("\n", "\n    ", (string) ($entry['text'] ?? ''));
            }
        }
        $lines[] = '';
        $lines[] = 'Reply to this email to answer the customer directly.';

        return implode("\n", $lines);
    }

    private function postWebhook(string $text): bool
    {
        $ch = curl_init($this->webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['text' => $text]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        $ok = curl_exec($ch) !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) < 300;
        curl_close($ch);

        return $ok;
    }
}

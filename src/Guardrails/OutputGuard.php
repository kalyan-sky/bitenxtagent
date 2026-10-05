<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Guardrails;

/**
 * Last line of defence: every reply is checked before it leaves the server.
 *
 * - Replies that look like they contain code, queries, internal paths,
 *   credentials or the system prompt are replaced with a safe message.
 * - Email addresses, phone numbers and card-like numbers are redacted unless
 *   they are the customer's own, the store's public contacts, or identifiers
 *   that came from the customer's own orders (order/tracking numbers, SKUs).
 */
final class OutputGuard
{
    public const BLOCKED_REPLY = "Sorry, I can't help with that here. I can check your orders, "
        . "shipping and tracking, or answer questions about our products and services.";

    private const LEAK_PATTERNS = [
        'code_fence' => '/```/',
        'php_code' => '/<\?php|\$this->|\bfunction\s+\w+\s*\(|\bnamespace\s+[A-Z]\w*\\\\/i',
        'sql' => '/\b(SELECT|INSERT|UPDATE|DELETE)\b[\s\S]{1,120}?\b(FROM|INTO|SET|WHERE)\b/',
        'graphql' => '/\b(query|mutation)\s*(\w+\s*)?(\([^)]*\)\s*)?\{/i',
        'stack_trace' => '/(#\d+\s+\/|Stack trace:|Fatal error|Uncaught\s+\w*Exception)/i',
        'server_path' => '/(\/(var|srv|home|opt|etc)\/[\w.\/-]+|app\/code\/|vendor\/[\w-]+\/|\b\w+\.php\b|\.env\b)/i',
        'internal_namespace' => '/\b(Bitenxt|Magento|Anthropic)\\\\\w+/',
        'credential' => '/(sk-ant-[\w-]+|Bearer\s+[A-Za-z0-9._-]{10,}|ANTHROPIC_API_KEY|MAGENTO_GRAPHQL_URL|HANDOFF_WEBHOOK_URL)/',
    ];

    private const EMAIL = '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i';
    private const PHONE_CANDIDATE = '/(?<![\w])\+?\(?\d[\d\s().-]{8,}\d(?![\w])/';
    private const CARD_CANDIDATE = '/(?<!\d)(?:\d[ -]?){12,18}\d(?!\d)/';

    /** @param list<string> $publicContacts store email/phone that may always be shown */
    public function __construct(
        private readonly string $canary,
        private readonly array $publicContacts = [],
    ) {
    }

    /**
     * @param list<string> $allowedValues identifiers from the customer's own data, plus their email
     */
    public function filter(string $reply, array $allowedValues): OutputCheck
    {
        if ($this->canary !== '' && str_contains($reply, $this->canary)) {
            return new OutputCheck(self::BLOCKED_REPLY, ['system_prompt_leak']);
        }

        $blocked = [];
        foreach (self::LEAK_PATTERNS as $name => $pattern) {
            if (preg_match($pattern, $reply)) {
                $blocked[] = $name;
            }
        }
        if ($blocked !== []) {
            return new OutputCheck(self::BLOCKED_REPLY, $blocked);
        }

        $allowed = array_merge($allowedValues, $this->publicContacts);
        $allowedLower = array_map('mb_strtolower', $allowed);
        $allowedDigits = array_values(array_filter(array_map(self::digits(...), $allowed), static fn ($d) => strlen($d) >= 6));
        $redactions = [];

        $reply = preg_replace_callback(self::EMAIL, static function (array $m) use ($allowedLower, &$redactions) {
            if (in_array(mb_strtolower($m[0]), $allowedLower, true)) {
                return $m[0];
            }
            $redactions[] = 'email';

            return '[email hidden]';
        }, $reply) ?? $reply;

        $reply = preg_replace_callback(self::CARD_CANDIDATE, static function (array $m) use ($allowedDigits, &$redactions) {
            $digits = self::digits($m[0]);
            if (in_array($digits, $allowedDigits, true) || !self::passesLuhn($digits)) {
                return $m[0];
            }
            $redactions[] = 'card_number';

            return '[number hidden]';
        }, $reply) ?? $reply;

        $reply = preg_replace_callback(self::PHONE_CANDIDATE, static function (array $m) use ($allowedDigits, &$redactions) {
            $digits = self::digits($m[0]);
            // Same number with or without the country code (+91 96422 03377 = 9642203377) is allowed too.
            $sameNumber = static fn (string $a): bool => strlen($a) >= 10 && substr($a, -10) === substr($digits, -10);
            if (
                strlen($digits) < 10 || strlen($digits) > 15
                || in_array($digits, $allowedDigits, true)
                || array_filter($allowedDigits, $sameNumber) !== []
                || preg_match('/\d{4}-\d{2}-\d{2}/', $m[0]) // dates such as 2026-09-29 10:15
            ) {
                return $m[0];
            }
            $redactions[] = 'phone';

            return '[phone hidden]';
        }, $reply) ?? $reply;

        return new OutputCheck($reply, [], array_values(array_unique($redactions)));
    }

    private static function digits(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? '';
    }

    private static function passesLuhn(string $digits): bool
    {
        $length = strlen($digits);
        if ($length < 13 || $length > 19) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < $length; $i++) {
            $d = (int) $digits[$length - 1 - $i];
            if ($i % 2 === 1) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
        }

        return $sum % 10 === 0;
    }
}

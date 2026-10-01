<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Support;

/**
 * Sends "a human should pick this up" requests to the support team. Always
 * appended to var/handoffs.jsonl; also POSTed to a webhook (Slack, Teams,
 * helpdesk) when HANDOFF_WEBHOOK_URL is set. This is staff-facing, so it may
 * include the customer's account email.
 */
class HandoffNotifier
{
    public function __construct(
        private readonly string $file,
        private readonly string $webhookUrl = '',
    ) {
    }

    /** @param array<string, mixed> $handoff */
    public function notify(array $handoff): bool
    {
        $handoff['ts'] = date(DATE_ATOM);
        file_put_contents($this->file, json_encode($handoff, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

        if ($this->webhookUrl === '') {
            return true;
        }

        $text = sprintf(
            "Chat handoff requested (%s)\nCustomer: %s\nOrder: %s\nReason: %s\nSummary: %s\nSession: %s",
            $handoff['urgency'] ?? 'normal',
            $handoff['customer_email'] ?? 'unknown',
            $handoff['order_number'] ?? '-',
            $handoff['reason'] ?? '',
            $handoff['summary'] ?? '',
            $handoff['session_id'] ?? '',
        );
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

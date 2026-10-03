<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Support;

/**
 * Sends "a human should pick this up" requests to the support team. Always
 * recorded (var/handoffs.jsonl locally, Cloud Logging on Cloud Run); also
 * POSTed to a webhook (Slack, Teams, helpdesk) when HANDOFF_WEBHOOK_URL is set. This is staff-facing, so it may
 * include the customer's account email.
 */
class HandoffNotifier
{
    public function __construct(
        private readonly string $file,
        private readonly string $webhookUrl = '',
        /** e.g. https://bitenxt-support-agent-xxxx.a.run.app; enables a transcript link in the message */
        private readonly string $staffBaseUrl = '',
    ) {
    }

    /** @param array<string, mixed> $handoff */
    public function notify(array $handoff): bool
    {
        $handoff['ts'] = date(DATE_ATOM);
        if ($this->staffBaseUrl !== '' && !empty($handoff['session_id'])) {
            $handoff['transcript_url'] = rtrim($this->staffBaseUrl, '/') . '/staff.html#conversation=' . $handoff['session_id'];
        }
        Logger::append($this->file, (string) json_encode(
            ['severity' => 'WARNING', 'message' => 'handoff', 'event' => 'handoff'] + $handoff,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        if ($this->webhookUrl === '') {
            return true;
        }

        $text = sprintf(
            "Chat handoff requested (%s)\nCustomer: %s\nOrder: %s\nReason: %s\nSummary: %s\nTranscript: %s",
            $handoff['urgency'] ?? 'normal',
            $handoff['customer_email'] ?? 'unknown',
            $handoff['order_number'] ?? '-',
            $handoff['reason'] ?? '',
            $handoff['summary'] ?? '',
            $handoff['transcript_url'] ?? ('session ' . ($handoff['session_id'] ?? '')),
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

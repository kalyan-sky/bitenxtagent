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
    private const NO_REQUEST = 'Not given: the customer asked to be connected without details. See the conversation below.';

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
            $delivered = $this->mailer->send(
                self::subject($handoff),
                self::body($handoff, $conversation),
                (string) ($handoff['customer_email'] ?? ''),
                self::html($handoff, $conversation),
            );
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
    public static function subject(array $handoff): string
    {
        $parts = [];
        if (($handoff['urgency'] ?? '') === 'high') {
            $parts[] = 'URGENT';
        }
        $request = self::request($handoff);
        $parts[] = self::reason($handoff) . ($request !== '' ? ': "' . (mb_strlen($request) > 60 ? rtrim(mb_substr($request, 0, 57)) . '…' : $request) . '"' : '');
        if (($handoff['order_number'] ?? '') !== '') {
            $parts[] = 'Order ' . $handoff['order_number'];
        }
        $name = trim((string) ($handoff['customer_name'] ?? ''));
        $email = (string) ($handoff['customer_email'] ?? '');
        $parts[] = $name !== '' ? ($email !== '' ? "{$name} ({$email})" : $name) : ($email !== '' ? $email : 'unknown customer');

        return '[BiteNXT Support] ' . implode(' | ', $parts);
    }

    /**
     * Plain-text version: the email's text part and the webhook message.
     *
     * @param array<string, mixed> $handoff
     * @param list<array{role: string, text: string, at?: int}> $conversation
     */
    public static function body(array $handoff, array $conversation): string
    {
        $field = static fn (string $label, string $value): string => '  ' . str_pad($label, 12) . ': '
            . str_replace("\n", "\n" . str_repeat(' ', 16), $value);
        $indent = static fn (string $text): string => '  ' . str_replace("\n", "\n  ", trim($text));
        $rule = str_repeat('-', 60);

        $lines = [
            'NEW SUPPORT REQUEST FROM THE BITENXT CHAT' . (self::urgent($handoff) ? '  ** URGENT **' : ''),
            str_repeat('=', 60),
            '',
            'WHAT THE CUSTOMER NEEDS',
            $indent(self::request($handoff) !== '' ? self::request($handoff) : self::NO_REQUEST),
            '',
            'REQUEST',
            $field('Reason', self::reason($handoff)),
            $field('Urgency', self::urgent($handoff) ? 'URGENT' : 'Normal'),
            $field('Order', self::value($handoff, 'order_number')),
            $field('Summary', self::value($handoff, 'summary')),
            $field('Received', self::when($handoff['ts'] ?? null)),
            '',
            'CUSTOMER',
            $field('Name', self::value($handoff, 'customer_name')),
            $field('Email', self::value($handoff, 'customer_email')),
            $field('Customer ID', self::value($handoff, 'customer_id')),
        ];

        if ($conversation !== []) {
            array_push($lines, '', 'RECENT CONVERSATION (newest first)', $rule);
            foreach (array_reverse($conversation) as $entry) {
                $time = isset($entry['at']) ? '  ' . self::when($entry['at'], 'h:i A') : '';
                array_push($lines, '[' . self::speaker($entry) . ']' . $time, $indent((string) ($entry['text'] ?? '')), '');
            }
            array_pop($lines);
        }

        $email = (string) ($handoff['customer_email'] ?? '');
        array_push(
            $lines,
            '',
            $rule,
            $email !== '' ? "Reply to this email to answer the customer directly (it goes to {$email})." : 'Reply to the customer from your support inbox.',
            'Chat session: ' . self::value($handoff, 'session_id'),
        );

        return implode("\n", $lines);
    }

    /**
     * HTML version for email clients: inline styles and tables only, every
     * value escaped (chat text is customer-written).
     *
     * @param array<string, mixed> $handoff
     * @param list<array{role: string, text: string, at?: int}> $conversation
     */
    public static function html(array $handoff, array $conversation): string
    {
        $e = static fn (string $text): string => nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
        $urgent = self::urgent($handoff);
        $row = static fn (string $label, string $valueHtml): string => '<tr>'
            . '<td style="padding:6px 12px 6px 0;color:#6b7680;width:120px;vertical-align:top;white-space:nowrap">' . $label . '</td>'
            . '<td style="padding:6px 0;color:#1d2327;vertical-align:top">' . $valueHtml . '</td></tr>';
        $section = static fn (string $title, string $inner): string => '<tr><td style="padding:20px 24px 0">'
            . '<div style="font-size:12px;font-weight:bold;letter-spacing:.6px;text-transform:uppercase;color:#d65897;'
            . 'border-bottom:1px solid #f0d3e1;padding-bottom:6px;margin-bottom:8px">' . $title . '</div>' . $inner . '</td></tr>';
        $table = static fn (string $rows): string => '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:14px">' . $rows . '</table>';

        $email = (string) ($handoff['customer_email'] ?? '');
        $emailHtml = $email !== '' ? '<a href="mailto:' . $e($email) . '" style="color:#d65897">' . $e($email) . '</a>' : '-';
        $badge = $urgent
            ? '<span style="display:inline-block;background:#c62828;color:#fff;font-weight:bold;font-size:12px;padding:2px 8px;border-radius:10px">URGENT</span>'
            : '<span style="display:inline-block;background:#e8f5e9;color:#2e7d32;font-size:12px;padding:2px 8px;border-radius:10px">Normal</span>';

        $html = '<!doctype html><html><body style="margin:0;padding:0;background:#f4f5f7">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;background:#f4f5f7;padding:24px 0"><tr><td align="center">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;max-width:640px;background:#ffffff;border:1px solid #e4e7eb;'
            . 'border-radius:8px;font-family:Arial,Helvetica,sans-serif;color:#1d2327">'
            . '<tr><td style="background:' . ($urgent ? '#c62828' : '#d65897') . ';color:#ffffff;padding:16px 24px;border-radius:8px 8px 0 0">'
            . '<div style="font-size:18px;font-weight:bold">' . ($urgent ? 'URGENT: ' : '') . 'New support request from chat</div>'
            . '<div style="font-size:13px;opacity:.9;margin-top:4px">' . $e(self::reason($handoff))
            . (($handoff['order_number'] ?? '') !== '' ? ' &middot; Order ' . $e((string) $handoff['order_number']) : '')
            . ' &middot; ' . $e(self::when($handoff['ts'] ?? null)) . '</div></td></tr>';

        $request = self::request($handoff);
        $html .= $section('What the customer needs', '<div style="background:#fdf2f7;border-left:4px solid #d65897;'
            . 'padding:10px 14px;font-size:15px;border-radius:4px">'
            . ($request !== '' ? $e($request) : '<span style="color:#6b7680">' . $e(self::NO_REQUEST) . '</span>') . '</div>');
        $html .= $section('Request', $table(
            $row('Reason', $e(self::reason($handoff)))
            . $row('Urgency', $badge)
            . $row('Order', '<strong>' . $e(self::value($handoff, 'order_number')) . '</strong>')
            . $row('Summary', $e(self::value($handoff, 'summary')))
            . $row('Received', $e(self::when($handoff['ts'] ?? null))),
        ));
        $html .= $section('Customer', $table(
            $row('Name', $e(self::value($handoff, 'customer_name')))
            . $row('Email', $emailHtml)
            . $row('Customer ID', $e(self::value($handoff, 'customer_id'))),
        ));

        if ($conversation !== []) {
            $messages = '';
            foreach (array_reverse($conversation) as $entry) {
                $isCustomer = ($entry['role'] ?? '') === 'user';
                $time = isset($entry['at']) ? ' <span style="font-weight:normal;color:#9aa3ab">' . $e(self::when($entry['at'], 'h:i A')) . '</span>' : '';
                $messages .= '<div style="margin:0 0 10px;padding:8px 12px;border-radius:6px;'
                    . ($isCustomer ? 'background:#fdf2f7;border:1px solid #f0d3e1' : 'background:#f7f8fa;border:1px solid #e4e7eb') . '">'
                    . '<div style="font-size:12px;font-weight:bold;color:' . ($isCustomer ? '#d65897' : '#6b7680') . ';margin-bottom:4px">'
                    . self::speaker($entry) . $time . '</div>'
                    . '<div style="font-size:14px;line-height:1.45">' . $e(trim((string) ($entry['text'] ?? ''))) . '</div></div>';
            }
            $html .= $section('Recent conversation <span style="text-transform:none;font-weight:normal;color:#9aa3ab">(newest first)</span>', $messages);
        }

        $html .= '<tr><td style="padding:16px 24px 20px;font-size:12px;color:#6b7680;border-top:1px solid #e4e7eb">'
            . ($email !== '' ? '<strong style="color:#1d2327">Reply to this email</strong> to answer the customer directly (it goes to ' . $e($email) . ').'
                : 'Reply to the customer from your support inbox.')
            . '<br>Chat session: ' . $e(self::value($handoff, 'session_id')) . '</td></tr>'
            . '</table></td></tr></table></body></html>';

        return $html;
    }

    /** @param array<string, mixed> $handoff */
    private static function reason(array $handoff): string
    {
        return ucfirst(str_replace('_', ' ', (string) ($handoff['reason'] ?? 'other')));
    }

    /** @param array<string, mixed> $handoff */
    private static function urgent(array $handoff): bool
    {
        return ($handoff['urgency'] ?? '') === 'high';
    }

    /** @param array<string, mixed> $handoff */
    private static function value(array $handoff, string $key): string
    {
        $value = trim((string) ($handoff[$key] ?? ''));

        return $value !== '' ? $value : '-';
    }

    /** @param array{role?: string} $entry */
    private static function speaker(array $entry): string
    {
        return ($entry['role'] ?? '') === 'user' ? 'Customer' : 'Chatbot';
    }

    /** @param array<string, mixed> $handoff */
    private static function request(array $handoff): string
    {
        return trim((string) ($handoff['request'] ?? ''));
    }

    /** Support works in India, so times are shown in IST. */
    private static function when(int|string|null $time, string $format = 'd M Y, h:i A'): string
    {
        try {
            $date = is_int($time) ? (new \DateTimeImmutable('@' . $time)) : new \DateTimeImmutable((string) ($time ?? 'now'));
        } catch (\Exception) {
            return (string) $time;
        }

        return $date->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format($format) . ' IST';
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

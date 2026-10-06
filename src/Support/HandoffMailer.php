<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Support;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Emails hand-over requests to the support inbox over SMTP (any provider:
 * Google Workspace / Gmail with an app password, Zoho, Microsoft 365, Brevo,
 * SendGrid...). Reply-To is the customer, so the team can answer directly.
 */
class HandoffMailer
{
    /** @param list<string> $to */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $from,
        private readonly array $to,
        private readonly string $encryption = 'tls',
        private readonly ?Logger $logger = null,
        private readonly int $timeoutSeconds = 10,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->host !== '' && $this->from !== '' && $this->to !== [];
    }

    /** @return bool true if the SMTP server accepted the message */
    public function send(string $subject, string $body, string $replyTo = ''): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }
        $mail = $this->newMailer();
        try {
            $mail->isSMTP();
            $mail->Host = $this->host;
            $mail->Port = $this->port;
            $mail->Timeout = $this->timeoutSeconds;
            $mail->SMTPAuth = $this->username !== '';
            $mail->Username = trim($this->username);
            $mail->Password = self::cleanPassword($this->host, $this->password);
            $mail->SMTPSecure = match ($this->encryption) {
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'none' => '',
                default => PHPMailer::ENCRYPTION_STARTTLS,
            };
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->setFrom($this->from, 'BiteNXT support chat');
            foreach ($this->to as $address) {
                $mail->addAddress($address);
            }
            if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                $mail->addReplyTo($replyTo);
            }
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->isHTML(false);
            $mail->send();

            return true;
        } catch (MailException $e) {
            // ErrorInfo never contains the password.
            $this->logger?->log('handoff_email_failed', ['detail' => mb_substr($mail->ErrorInfo ?: $e->getMessage(), 0, 300)]);

            return false;
        }
    }

    /**
     * Secrets pasted into Secret Manager often end with a newline, and Google
     * shows app passwords as "abcd efgh ijkl mnop". Google app passwords never
     * contain spaces, so those are removed; other providers' passwords are only
     * trimmed (they may legitimately contain spaces).
     */
    public static function cleanPassword(string $host, string $password): string
    {
        $password = trim($password, "\r\n\t ");

        return preg_match('/(^|\.)(gmail|googlemail)\.com$/i', $host) ? (string) preg_replace('/\s+/', '', $password) : $password;
    }

    /** Separate so tests can capture messages instead of sending them. */
    protected function newMailer(): PHPMailer
    {
        return new PHPMailer(true);
    }
}

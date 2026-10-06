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
        /** SMTP login method. PHPMailer otherwise prefers CRAM-MD5, which some providers' keys fail (e.g. Brevo). */
        private readonly string $authType = 'LOGIN',
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->host !== '' && $this->from !== '' && $this->to !== [];
    }

    /**
     * Sends one copy to each inbox, so one bad address (a typo, a full or
     * closed mailbox) can't stop the others from getting it.
     *
     * @return bool true if at least one inbox accepted the message
     */
    public function send(string $subject, string $body, string $replyTo = ''): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }
        $delivered = false;
        foreach ($this->to as $address) {
            $error = $this->sendOne($address, $subject, $body, $replyTo);
            if ($error === null) {
                $delivered = true;
            } else {
                // ErrorInfo never contains the password; staff addresses are fine to log.
                $this->logger?->log('handoff_email_failed', ['to' => $address, 'detail' => mb_substr($error, 0, 300)]);
            }
        }

        return $delivered;
    }

    /** @return string|null null when the server accepted it, otherwise the error */
    protected function sendOne(string $to, string $subject, string $body, string $replyTo): ?string
    {
        $mail = $this->newMailer();
        try {
            $mail->isSMTP();
            $mail->Host = $this->host;
            $mail->Port = $this->port;
            $mail->Timeout = $this->timeoutSeconds;
            $mail->SMTPAuth = $this->username !== '';
            $mail->Username = $this->username;
            $mail->Password = $this->password;
            $mail->AuthType = $this->authType; // '' lets PHPMailer choose
            $mail->SMTPSecure = match ($this->encryption) {
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'none' => '',
                default => PHPMailer::ENCRYPTION_STARTTLS,
            };
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->setFrom($this->from, 'BiteNXT support chat');
            $mail->addAddress($to);
            if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                $mail->addReplyTo($replyTo);
            }
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->isHTML(false);
            $mail->send();

            return null;
        } catch (MailException $e) {
            return $mail->ErrorInfo ?: $e->getMessage();
        }
    }

    /** Separate so tests can capture messages instead of sending them. */
    protected function newMailer(): PHPMailer
    {
        return new PHPMailer(true);
    }
}

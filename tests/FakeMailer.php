<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Tests;

use Bitenxt\SupportAgent\Support\HandoffMailer;

/** Records hand-over emails instead of sending them. */
final class FakeMailer extends HandoffMailer
{
    /** @var list<array{subject: string, body: string, replyTo: string}> */
    public array $sent = [];
    public bool $fail = false;

    public function __construct()
    {
        parent::__construct('smtp.test', 587, 'bot@bitenxt.test', 'secret', 'bot@bitenxt.test', ['support@bitenxt.com']);
    }

    public function send(string $subject, string $body, string $replyTo = ''): bool
    {
        if ($this->fail) {
            return false;
        }
        $this->sent[] = ['subject' => $subject, 'body' => $body, 'replyTo' => $replyTo];

        return true;
    }
}

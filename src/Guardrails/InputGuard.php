<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Guardrails;

/**
 * First line of defence on what the customer types. It normalises the text
 * and flags likely prompt-injection or data-extraction attempts for the logs.
 * Flagged messages still go to the model (which is instructed to decline),
 * because the real protection is structural: the tools can only reach the
 * signed-in customer's own data, and OutputGuard checks every reply.
 */
final class InputGuard
{
    private const SUSPICIOUS_PATTERNS = [
        'prompt_injection' => '/\b(ignore|disregard|forget|override)\b.{0,40}\b(previous|prior|above|all|your)\b.{0,20}\b(instructions?|rules?|prompts?|guidelines)\b/i',
        'role_override' => '/\b(you are now|act as|pretend (to be|you are)|developer mode|jailbreak|DAN mode)\b/i',
        'system_prompt_probe' => '/\b(system prompt|initial prompt|your (instructions|rules|prompt)|hidden (instructions|prompt))\b/i',
        'code_probe' => '/\b(source code|php code|your code|graphql (schema|query|mutation)|sql|database|api key|access token|env(ironment)? variables?|\.env)\b/i',
        'other_customer_probe' => '/\b(another|other|different)\b.{0,20}\b(customer|clinic|doctor|account|user)s?\b.{0,40}\b(order|orders|data|details|address|email|phone|patients?)\b/i',
    ];

    public function __construct(private readonly int $maxChars)
    {
    }

    public function check(string $raw): InputCheck
    {
        // Strip control characters (keep newlines/tabs) and invisible
        // formatting characters often used to smuggle instructions.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u', '', $raw) ?? '';
        $text = trim($text);

        if ($text === '') {
            return new InputCheck(false, '', 'Please type a message.');
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            return new InputCheck(false, '', 'Sorry, I could not read that message. Please try again.');
        }
        if (mb_strlen($text) > $this->maxChars) {
            return new InputCheck(
                false,
                '',
                sprintf('That message is a bit long. Please keep it under %d characters.', $this->maxChars),
            );
        }

        $flags = [];
        foreach (self::SUSPICIOUS_PATTERNS as $flag => $pattern) {
            if (preg_match($pattern, $text)) {
                $flags[] = $flag;
            }
        }

        return new InputCheck(true, $text, null, $flags);
    }
}

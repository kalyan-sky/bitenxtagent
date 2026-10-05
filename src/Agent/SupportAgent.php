<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Agent;

use Bitenxt\SupportAgent\Budget\BudgetExceededException;
use Bitenxt\SupportAgent\Budget\TokenBudget;
use Bitenxt\SupportAgent\Llm\LlmProvider;
use Bitenxt\SupportAgent\Llm\LlmResponse;
use Bitenxt\SupportAgent\Llm\LlmUnavailableException;
use Bitenxt\SupportAgent\Session\ChatSession;
use Bitenxt\SupportAgent\Support\Logger;
use Bitenxt\SupportAgent\Support\Timing;

/**
 * Tool-use loop over a chain of AI providers (e.g. Gemini first, Claude as
 * fallback). For each message the providers are tried in order: if one is
 * down, rate-limited, refuses, or is over its own token limit, the next one
 * answers. Every single AI call is checked against the token budget first.
 *
 * Each provider keeps the conversation in its own format. When the answering
 * provider differs from the one that holds the history, or the history is
 * longer than $historyTurns, it starts from a fresh context seeded with the
 * last messages the customer saw. That keeps the tokens per call (the cost)
 * flat however long the chat gets.
 *
 * History is append-only and only committed when a turn finishes cleanly.
 */
final class SupportAgent
{
    public const MAX_TOOL_ROUNDS = 6;
    public const FALLBACK_REPLY = "Sorry, I couldn't complete that just now. Please try again in a moment, "
        . 'or ask me to connect you with our support team.';
    public const UNAVAILABLE_REPLY = 'Chat is temporarily unavailable. Please try again later.';

    /** @param list<LlmProvider> $providers in order of preference */
    public function __construct(
        private readonly array $providers,
        private readonly string $systemPrompt,
        private readonly TokenBudget $budget,
        private readonly Logger $logger,
        private readonly int $maxOutputTokens = 1024,
        private readonly string $customerLimitReply = "You've reached today's chat limit. Please try again tomorrow.",
        private readonly int $historyTurns = 6,
    ) {
    }

    /** @return array{reply: string, stop_reason: string, provider?: string} */
    public function respond(ChatSession $session, string $userText, SupportTools $tools, string $customerKey): array
    {
        foreach ($this->providers as $provider) {
            try {
                $result = $this->run($provider, $session, $userText, $tools, $customerKey);
            } catch (LlmUnavailableException $e) {
                $this->logger->log('llm_fallback', ['session' => $session->id, 'provider' => $provider->name(), 'reason' => 'unavailable', 'detail' => $e->getMessage()]);
                continue;
            } catch (BudgetExceededException $e) {
                if ($e->scope === BudgetExceededException::PROVIDER) {
                    $this->logger->log('llm_fallback', ['session' => $session->id, 'provider' => $provider->name(), 'reason' => 'provider_token_limit']);
                    continue;
                }

                return $e->scope === BudgetExceededException::CUSTOMER
                    ? ['reply' => $this->customerLimitReply, 'stop_reason' => 'customer_token_limit']
                    : ['reply' => self::UNAVAILABLE_REPLY, 'stop_reason' => $e->scope === BudgetExceededException::GLOBAL ? 'global_token_limit' : 'token_budget_unavailable'];
            }

            if ($result['stop_reason'] === LlmResponse::REFUSAL) {
                // e.g. a safety filter misreading dental wording: let the next provider try.
                $this->logger->log('llm_fallback', ['session' => $session->id, 'provider' => $provider->name(), 'reason' => 'refusal']);
                continue;
            }

            return $result + ['provider' => $provider->name()];
        }

        return ['reply' => self::FALLBACK_REPLY, 'stop_reason' => 'all_providers_failed'];
    }

    /**
     * @return array{reply: string, stop_reason: string}
     * @throws LlmUnavailableException|BudgetExceededException
     */
    private function run(LlmProvider $provider, ChatSession $session, string $userText, SupportTools $tools, string $customerKey): array
    {
        $messages = match (true) {
            $session->provider === '' => [],               // no AI history yet in this conversation
            // Same provider and still short: keep the full history, tool results included.
            $session->provider === $provider->name() && count($session->transcript) <= 2 * $this->historyTurns
                => $session->messages,
            // Another provider (or the fast path) answered last, or the history is long:
            // start from the last few messages the customer saw, which keeps every call small.
            default => $this->seed($provider, $session),
        };
        $messages[] = $provider->userMessage($userText);
        $definitions = SupportTools::definitions();

        for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++) {
            $reservation = $this->budget->reserve($customerKey, $provider->name(), $this->estimate($messages));
            try {
                $started = hrtime(true);
                $response = Timing::measure('llm', fn () => $provider->complete($this->systemPrompt, $definitions, $messages, $this->maxOutputTokens));
            } catch (LlmUnavailableException $e) {
                $this->budget->release($reservation);
                throw $e;
            }
            $this->budget->settle($reservation, $response->totalTokens());
            $this->logger->log('llm_call', [
                'session' => $session->id,
                'provider' => $provider->name(),
                'model' => $provider->model(),
                'input_tokens' => $response->inputTokens,
                'output_tokens' => $response->outputTokens,
                'stop_reason' => $response->stopReason,
                'ms' => (int) ((hrtime(true) - $started) / 1e6),
            ]);

            if ($response->stopReason === LlmResponse::REFUSAL) {
                return ['reply' => self::FALLBACK_REPLY, 'stop_reason' => LlmResponse::REFUSAL];
            }

            $messages[] = $response->assistantMessage;

            if ($response->stopReason !== LlmResponse::TOOL_USE) {
                if ($response->text === '') {
                    // Nothing usable (e.g. the output cap was hit mid-thought): keep no half turn.
                    return ['reply' => self::FALLBACK_REPLY, 'stop_reason' => $response->stopReason];
                }
                $session->messages = $messages;
                $session->provider = $provider->name();

                return ['reply' => $response->text, 'stop_reason' => $response->stopReason];
            }

            if ($round === self::MAX_TOOL_ROUNDS) {
                break; // never leave an unanswered tool call in the saved history
            }

            $results = [];
            foreach ($response->toolCalls as $call) {
                [$content, $isError] = isset($call->input['__invalid_json'])
                    ? [(string) json_encode(['error' => 'invalid_arguments']), true]
                    : $tools->execute($call->name, $call->input);
                $results[] = ['id' => $call->id, 'name' => $call->name, 'content' => $content, 'isError' => $isError];
            }
            $messages = array_merge($messages, $provider->toolResultMessages($results));
        }

        return ['reply' => self::FALLBACK_REPLY, 'stop_reason' => 'max_tool_rounds'];
    }

    /**
     * Starting history for a provider that doesn't hold this conversation:
     * the last few messages the customer saw, as plain text.
     *
     * @return list<array<string, mixed>>
     */
    private function seed(LlmProvider $provider, ChatSession $session): array
    {
        $recent = array_slice($session->transcript, -2 * $this->historyTurns);
        while ($recent !== [] && $recent[0]['role'] !== 'user') {
            array_shift($recent);
        }

        $messages = [];
        foreach ($recent as $entry) {
            $messages[] = $entry['role'] === 'user'
                ? $provider->userMessage($entry['text'])
                : $provider->assistantMessage($entry['text']);
        }
        // The new user message goes next, so the seed must end with the assistant.
        if ($messages !== [] && end($recent)['role'] === 'user') {
            array_pop($messages);
        }

        return $messages;
    }

    /**
     * Over-estimate of the tokens one call can use: about one token per
     * 3 bytes of everything sent (prompt, tool definitions, history) plus the
     * full output cap. Corrected to the real number after the call.
     *
     * @param list<array<string, mixed>> $messages
     */
    private function estimate(array $messages): int
    {
        $bytes = strlen($this->systemPrompt) + strlen((string) json_encode(SupportTools::definitions()))
            + strlen((string) json_encode($messages));

        return intdiv($bytes, 3) + $this->maxOutputTokens;
    }
}

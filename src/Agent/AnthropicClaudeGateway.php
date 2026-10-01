<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Agent;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Client;

final class AnthropicClaudeGateway implements ClaudeGateway
{
    public function __construct(
        private readonly Client $client,
        private readonly string $model,
        private readonly string $effort,
    ) {
    }

    public function create(string $system, array $tools, array $messages): BetaMessage
    {
        return $this->client->beta->messages->create(
            model: $this->model,
            maxTokens: 16000,
            // The system prompt and tool list never change, so they are cached
            // across all conversations; the top-level cacheControl also caches
            // the growing conversation between tool-loop steps.
            system: [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]],
            cacheControl: ['type' => 'ephemeral'],
            tools: $tools,
            messages: $messages,
            // Support chat does well at low effort; raise CLAUDE_EFFORT if needed.
            outputConfig: ['effort' => $this->effort],
            // If a safety classifier declines (e.g. a false positive on dental
            // or medical wording), retry on the server-chosen fallback model.
            fallbacks: 'default',
            betas: ['server-side-fallback-2026-07-01'],
        );
    }
}

<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Agent;

use Anthropic\Beta\Messages\BetaMessage;

/** Seam between the agent loop and the Anthropic SDK, so the loop can be tested offline. */
interface ClaudeGateway
{
    /**
     * @param list<array<string, mixed>> $tools
     * @param list<array<string, mixed>> $messages
     */
    public function create(string $system, array $tools, array $messages): BetaMessage;
}

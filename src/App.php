<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent;

use Anthropic\Client;
use Bitenxt\SupportAgent\Agent\AnthropicClaudeGateway;
use Bitenxt\SupportAgent\Agent\SupportAgent;
use Bitenxt\SupportAgent\Agent\SystemPrompt;
use Bitenxt\SupportAgent\Chat\ChatService;
use Bitenxt\SupportAgent\Guardrails\InputGuard;
use Bitenxt\SupportAgent\Guardrails\OutputGuard;
use Bitenxt\SupportAgent\Guardrails\RateLimiter;
use Bitenxt\SupportAgent\Knowledge\KnowledgeBase;
use Bitenxt\SupportAgent\Magento\GraphQLClient;
use Bitenxt\SupportAgent\Magento\MagentoCustomerDataSource;
use Bitenxt\SupportAgent\Session\SessionStore;
use Bitenxt\SupportAgent\Support\HandoffNotifier;
use Bitenxt\SupportAgent\Support\Logger;

/** Wires the production object graph from Config. */
final class App
{
    public static function chatService(Config $config): ChatService
    {
        $storage = $config->storageDir;
        $canary = self::canary($storage);
        $logger = new Logger($storage . '/logs/chat.jsonl');
        $magento = new MagentoCustomerDataSource(new GraphQLClient($config->magentoGraphqlUrl, $config->magentoTimeoutSeconds));

        $agent = new SupportAgent(
            new AnthropicClaudeGateway(new Client(apiKey: $config->anthropicApiKey), $config->model, $config->effort),
            SystemPrompt::build($config->storeName, $config->supportEmail, $config->supportPhone, $canary),
        );

        return new ChatService(
            sessions: new SessionStore($storage . '/sessions', $config->sessionTtlSeconds),
            rateLimiter: new RateLimiter($storage . '/ratelimit', $config->rateLimitPerMinute, $config->rateLimitPerDay),
            inputGuard: new InputGuard($config->maxMessageChars),
            outputGuard: new OutputGuard($canary, array_values(array_filter([$config->supportEmail, $config->supportPhone]))),
            agent: $agent,
            magento: $magento,
            knowledge: new KnowledgeBase(dirname(__DIR__) . '/knowledge'),
            handoff: new HandoffNotifier($storage . '/handoffs.jsonl', $config->handoffWebhookUrl),
            logger: $logger,
            maxTurnsPerSession: $config->maxTurnsPerSession,
        );
    }

    /**
     * A random marker embedded in the system prompt. If it ever shows up in a
     * reply, the prompt is being leaked and OutputGuard blocks the reply.
     * Stored on disk so the prompt (and its cache) stays stable across requests.
     */
    private static function canary(string $storage): string
    {
        $file = $storage . '/canary.txt';
        if (!is_file($file)) {
            if (!is_dir($storage)) {
                mkdir($storage, 0700, true);
            }
            file_put_contents($file, 'ref-' . bin2hex(random_bytes(8)), LOCK_EX);
        }

        return trim((string) file_get_contents($file));
    }
}

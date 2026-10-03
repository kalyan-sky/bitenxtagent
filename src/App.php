<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent;

use Anthropic\Client;
use Bitenxt\SupportAgent\Agent\AnthropicClaudeGateway;
use Bitenxt\SupportAgent\Agent\SupportAgent;
use Bitenxt\SupportAgent\Agent\SystemPrompt;
use Bitenxt\SupportAgent\Chat\ChatService;
use Bitenxt\SupportAgent\Gcp\FirestoreClient;
use Bitenxt\SupportAgent\Guardrails\FileRateLimiter;
use Bitenxt\SupportAgent\Guardrails\FirestoreRateLimiter;
use Bitenxt\SupportAgent\Guardrails\InputGuard;
use Bitenxt\SupportAgent\Guardrails\OutputGuard;
use Bitenxt\SupportAgent\Knowledge\KnowledgeBase;
use Bitenxt\SupportAgent\Magento\GraphQLClient;
use Bitenxt\SupportAgent\Magento\MagentoCustomerDataSource;
use Bitenxt\SupportAgent\Session\FileSessionStore;
use Bitenxt\SupportAgent\Session\FirestoreSessionStore;
use Bitenxt\SupportAgent\Support\HandoffNotifier;
use Bitenxt\SupportAgent\Support\Logger;

/** Wires the production object graph from Config. */
final class App
{
    public static function chatService(Config $config): ChatService
    {
        $storage = $config->storageDir;
        $canary = self::canary($config);
        $onCloud = $config->logTarget === 'stderr';
        $logger = new Logger($onCloud ? 'php://stderr' : $storage . '/logs/chat.jsonl');
        $magento = new MagentoCustomerDataSource(new GraphQLClient($config->magentoGraphqlUrl, $config->magentoTimeoutSeconds));

        if ($config->storageBackend === 'firestore') {
            $firestore = new FirestoreClient($config->gcpProject, $config->firestoreDatabase, $config->firestoreEmulatorHost);
            $sessions = new FirestoreSessionStore($firestore, $config->sessionTtlSeconds);
            $rateLimiter = new FirestoreRateLimiter($firestore, $config->rateLimitPerMinute, $config->rateLimitPerDay);
        } else {
            $sessions = new FileSessionStore($storage . '/sessions', $config->sessionTtlSeconds);
            $rateLimiter = new FileRateLimiter($storage . '/ratelimit', $config->rateLimitPerMinute, $config->rateLimitPerDay);
        }

        $agent = new SupportAgent(
            new AnthropicClaudeGateway(new Client(apiKey: $config->anthropicApiKey), $config->model, $config->effort),
            SystemPrompt::build($config->storeName, $config->supportEmail, $config->supportPhone, $canary),
        );

        return new ChatService(
            sessions: $sessions,
            rateLimiter: $rateLimiter,
            inputGuard: new InputGuard($config->maxMessageChars),
            outputGuard: new OutputGuard($canary, array_values(array_filter([$config->supportEmail, $config->supportPhone]))),
            agent: $agent,
            magento: $magento,
            knowledge: new KnowledgeBase(dirname(__DIR__) . '/knowledge'),
            handoff: new HandoffNotifier($onCloud ? 'php://stderr' : $storage . '/handoffs.jsonl', $config->handoffWebhookUrl),
            logger: $logger,
            maxTurnsPerSession: $config->maxTurnsPerSession,
        );
    }

    /**
     * A marker embedded in the system prompt. If it ever shows up in a reply,
     * the prompt is being leaked and OutputGuard blocks the reply. It must be
     * identical on every instance (and stable over time) so the prompt cache
     * is shared, so it is derived from a secret rather than generated per box.
     */
    private static function canary(Config $config): string
    {
        $secret = $config->promptCanary !== '' ? $config->promptCanary : $config->anthropicApiKey;

        return 'ref-' . substr(hash_hmac('sha256', 'bitenxt-prompt-canary', $secret), 0, 16);
    }
}

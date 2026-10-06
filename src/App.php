<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent;

use Anthropic\Client;
use Bitenxt\SupportAgent\Agent\AnthropicClaudeGateway;
use Bitenxt\SupportAgent\Agent\SupportAgent;
use Bitenxt\SupportAgent\Agent\SystemPrompt;
use Bitenxt\SupportAgent\Budget\FileTokenCounter;
use Bitenxt\SupportAgent\Budget\FirestoreTokenCounter;
use Bitenxt\SupportAgent\Budget\TokenBudget;
use Bitenxt\SupportAgent\Chat\ChatService;
use Bitenxt\SupportAgent\Chat\FastPath;
use Bitenxt\SupportAgent\Gcp\FirestoreClient;
use Bitenxt\SupportAgent\Guardrails\FileRateLimiter;
use Bitenxt\SupportAgent\Guardrails\FirestoreRateLimiter;
use Bitenxt\SupportAgent\Guardrails\InputGuard;
use Bitenxt\SupportAgent\Guardrails\OutputGuard;
use Bitenxt\SupportAgent\Knowledge\KnowledgeBase;
use Bitenxt\SupportAgent\Llm\AnthropicProvider;
use Bitenxt\SupportAgent\Llm\LlmProvider;
use Bitenxt\SupportAgent\Llm\OpenAiCompatibleProvider;
use Bitenxt\SupportAgent\Llm\ProviderSettings;
use Bitenxt\SupportAgent\Magento\GraphQLClient;
use Bitenxt\SupportAgent\Magento\MagentoCustomerDataSource;
use Bitenxt\SupportAgent\Session\FileSessionStore;
use Bitenxt\SupportAgent\Session\FirestoreSessionStore;
use Bitenxt\SupportAgent\Support\HandoffMailer;
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
        $magento = new MagentoCustomerDataSource(new GraphQLClient($config->magentoGraphqlUrl, $config->magentoTimeoutSeconds), $logger);

        if ($config->storageBackend === 'firestore') {
            $firestore = new FirestoreClient($config->gcpProject, $config->firestoreDatabase, $config->firestoreEmulatorHost);
            $sessions = new FirestoreSessionStore($firestore, $config->historyRetentionSeconds);
            $rateLimiter = new FirestoreRateLimiter($firestore, $config->rateLimitPerMinute, $config->rateLimitPerDay);
        } else {
            $sessions = new FileSessionStore($storage . '/sessions', $config->historyRetentionSeconds);
            $rateLimiter = new FileRateLimiter($storage . '/ratelimit', $config->rateLimitPerMinute, $config->rateLimitPerDay);
        }

        if ($config->storageBackend === 'firestore') {
            $tokenCounter = new FirestoreTokenCounter($firestore);
        } else {
            $tokenCounter = new FileTokenCounter($storage . '/token_usage.json');
        }
        $budget = new TokenBudget(
            $tokenCounter,
            $logger,
            $config->tokenLimitCustomerPerDay,
            $config->tokenLimitGlobalPerHour,
            $config->tokenLimitGlobalPerDay,
            array_column(array_map(static fn ($p) => [$p->name, $p->dailyTokenLimit], $config->llmProviders), 1, 0),
        );

        $agent = new SupportAgent(
            self::providers($config, $logger),
            SystemPrompt::build($config->storeName, $config->supportEmail, $config->supportPhone, $canary, $config->proPortalUrl),
            $budget,
            $logger,
            $config->maxOutputTokens,
            "You've reached today's chat limit. Please try again tomorrow"
                . match (true) {
                    $config->supportPhone !== '' => ', or call ' . $config->supportPhone
                        . ($config->supportEmail !== '' ? ' / email ' . $config->supportEmail : '') . ' if it is urgent.',
                    $config->supportEmail !== '' => ', or email ' . $config->supportEmail . ' if it is urgent.',
                    default => '.',
                },
            $config->aiHistoryTurns,
        );
        $knowledge = new KnowledgeBase(dirname(__DIR__) . '/knowledge');
        return new ChatService(
            sessions: $sessions,
            rateLimiter: $rateLimiter,
            inputGuard: new InputGuard($config->maxMessageChars),
            outputGuard: new OutputGuard($canary, array_values(array_filter([$config->supportEmail, $config->supportPhone]))),
            agent: $agent,
            magento: $magento,
            knowledge: $knowledge,
            handoff: new HandoffNotifier(
                $onCloud ? 'php://stderr' : $storage . '/handoffs.jsonl',
                $config->handoffWebhookUrl,
                new HandoffMailer($config->smtpHost, $config->smtpPort, $config->smtpUsername, $config->smtpPassword,
                    $config->smtpFrom, $config->handoffEmailTo, $config->smtpEncryption, $logger, 10, $config->smtpAuthType),
                $logger,
            ),
            logger: $logger,
            maxTurnsPerConversation: $config->maxTurnsPerConversation,
            conversationIdleSeconds: $config->conversationIdleSeconds,
            // In "primary" mode the AI answers how-to questions; otherwise clear ones come straight from the articles.
            fastPath: $config->fastPathEnabled
                ? new FastPath($config->aiMode === ChatService::AI_PRIMARY ? null : $knowledge, $config->supportPhone, $config->supportEmail)
                : null,
            aiMode: $config->aiMode,
        );
    }

    /**
     * The configured AI providers, in order. Misconfigured ones are skipped
     * and logged, so one bad setting can't take the whole chat down.
     *
     * @return list<LlmProvider>
     */
    public static function providers(Config $config, Logger $logger): array
    {
        $providers = [];
        foreach ($config->llmProviders as $settings) {
            if (($problem = $settings->problem()) !== null) {
                $logger->log('llm_config_error', ['provider' => $settings->name, 'problem' => $problem]);
                continue;
            }
            $providers[] = $settings->type === ProviderSettings::ANTHROPIC
                ? new AnthropicProvider($settings->name, $settings->model, array_map(
                    static fn (string $key) => new AnthropicClaudeGateway(
                        new Client(apiKey: $key, requestOptions: ['timeout' => (float) $settings->timeoutSeconds, 'maxRetries' => 1]),
                        $settings->model,
                        $settings->effort,
                    ),
                    $settings->apiKeys,
                ))
                : new OpenAiCompatibleProvider(
                    $settings->name,
                    $settings->model,
                    $settings->baseUrl,
                    $settings->apiKeys,
                    $settings->maxTokensParam,
                    $settings->timeoutSeconds,
                );
        }
        if ($providers === []) {
            $logger->log('llm_config_error', ['problem' => 'no usable AI provider; set LLM_PROVIDERS and its keys']);
        }

        return $providers;
    }

    /**
     * A marker embedded in the system prompt. If it ever shows up in a reply,
     * the prompt is being leaked and OutputGuard blocks the reply. It must be
     * identical on every instance (and stable over time) so the prompt cache
     * is shared, so it is derived from a secret rather than generated per box.
     */
    private static function canary(Config $config): string
    {
        $secret = $config->promptCanary !== '' ? $config->promptCanary : ($config->llmProviders[0]->apiKeys[0] ?? 'bitenxt');

        return 'ref-' . substr(hash_hmac('sha256', 'bitenxt-prompt-canary', $secret), 0, 16);
    }
}

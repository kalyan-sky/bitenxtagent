<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent;

use Bitenxt\SupportAgent\Llm\ProviderSettings;

/**
 * Typed view over environment variables. Secrets are read once here and never
 * passed to the model or written to logs.
 */
final class Config
{
    public function __construct(
        /** @var list<ProviderSettings> AI providers in order: primary first, then fallbacks */
        public readonly array $llmProviders,
        public readonly string $magentoGraphqlUrl,
        public readonly int $magentoTimeoutSeconds,
        /** @var list<string> */
        public readonly array $allowedOrigins,
        public readonly string $storeName,
        public readonly string $supportEmail,
        public readonly string $supportPhone,
        public readonly int $maxMessageChars,
        /** user messages per conversation before the next message starts a fresh one */
        public readonly int $maxTurnsPerConversation,
        public readonly int $rateLimitPerMinute,
        public readonly int $rateLimitPerDay,
        /** how long chat history is kept after the last message */
        public readonly int $historyRetentionSeconds,
        public readonly string $handoffWebhookUrl,
        public readonly string $storageDir,
        /** "file" (local dev, single server) or "firestore" (Cloud Run) */
        public readonly string $storageBackend = 'file',
        public readonly string $gcpProject = '',
        public readonly string $firestoreDatabase = '(default)',
        public readonly string $firestoreEmulatorHost = '',
        /** "file" writes var/logs; "stderr" sends structured logs to Cloud Logging */
        public readonly string $logTarget = 'file',
        public readonly string $promptCanary = '',
        /** proxies in front of the app that append to X-Forwarded-For (Cloud Run itself = 1) */
        public readonly int $trustedProxyHops = 0,
        /** a quiet spell longer than this starts a fresh conversation (the window keeps earlier messages) */
        public readonly int $conversationIdleSeconds = 1800,
        /** most tokens one AI reply may generate */
        public readonly int $maxOutputTokens = 400,
        /** token limits (input + output); 0 = no limit */
        public readonly int $tokenLimitCustomerPerDay = 100000,
        public readonly int $tokenLimitGlobalPerHour = 200000,
        public readonly int $tokenLimitGlobalPerDay = 1000000,
        public readonly int $aiHistoryTurns = 6,
        public readonly string $proPortalUrl = '',
        public readonly bool $fastPathEnabled = true,
    ) {
    }

    public static function fromEnvironment(?string $envFile = null): self
    {
        if ($envFile !== null && is_readable($envFile)) {
            self::loadEnvFile($envFile);
        }

        $env = static fn (string $key, string $default = ''): string =>
            ($value = getenv($key)) === false || $value === '' ? $default : $value;

        return new self(
            llmProviders: ProviderSettings::fromEnvironment($env),
            magentoGraphqlUrl: $env('MAGENTO_GRAPHQL_URL'),
            magentoTimeoutSeconds: (int) $env('MAGENTO_TIMEOUT_SECONDS', '10'),
            allowedOrigins: array_values(array_filter(array_map('trim', explode(',', $env('ALLOWED_ORIGINS'))))),
            storeName: $env('STORE_NAME', 'BiteNXT'),
            supportEmail: $env('SUPPORT_EMAIL'),
            supportPhone: $env('SUPPORT_PHONE'),
            maxMessageChars: (int) $env('MAX_MESSAGE_CHARS', '2000'),
            maxTurnsPerConversation: (int) $env('MAX_TURNS_PER_CONVERSATION', $env('MAX_TURNS_PER_SESSION', '40')),
            rateLimitPerMinute: (int) $env('RATE_LIMIT_PER_MINUTE', '10'),
            rateLimitPerDay: (int) $env('RATE_LIMIT_PER_DAY', '200'),
            historyRetentionSeconds: 86400 * (int) $env('HISTORY_RETENTION_DAYS', '90'),
            handoffWebhookUrl: $env('HANDOFF_WEBHOOK_URL'),
            storageDir: rtrim($env('STORAGE_DIR', dirname(__DIR__) . '/var'), '/'),
            storageBackend: $env('STORAGE_BACKEND', 'file'),
            gcpProject: $env('GCP_PROJECT', $env('GOOGLE_CLOUD_PROJECT')),
            firestoreDatabase: $env('FIRESTORE_DATABASE', '(default)'),
            firestoreEmulatorHost: $env('FIRESTORE_EMULATOR_HOST'),
            logTarget: $env('LOG_TARGET', 'file'),
            promptCanary: $env('PROMPT_CANARY'),
            trustedProxyHops: (int) $env('TRUSTED_PROXY_HOPS', '0'),
            conversationIdleSeconds: 60 * (int) $env('CONVERSATION_IDLE_MINUTES', '30'),
            maxOutputTokens: (int) $env('MAX_OUTPUT_TOKENS', '400'),
            tokenLimitCustomerPerDay: (int) $env('TOKEN_LIMIT_CUSTOMER_PER_DAY', '100000'),
            tokenLimitGlobalPerHour: (int) $env('TOKEN_LIMIT_GLOBAL_PER_HOUR', '200000'),
            tokenLimitGlobalPerDay: (int) $env('TOKEN_LIMIT_GLOBAL_PER_DAY', '1000000'),
            aiHistoryTurns: max(1, (int) $env('AI_HISTORY_TURNS', '6')),
            proPortalUrl: rtrim($env('PRO_PORTAL_URL'), '/'),
            fastPathEnabled: !in_array(strtolower($env('FAST_PATH', 'on')), ['off', 'false', '0', 'no'], true),
        );
    }

    private static function loadEnvFile(string $path): void
    {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            // Real environment variables win over the .env file.
            if (getenv($key) === false) {
                putenv($key . '=' . trim($value, "\"'"));
            }
        }
    }
}

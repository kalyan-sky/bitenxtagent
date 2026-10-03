<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent;

/**
 * Typed view over environment variables. Secrets are read once here and never
 * passed to the model or written to logs.
 */
final class Config
{
    public function __construct(
        public readonly string $anthropicApiKey,
        public readonly string $model,
        public readonly string $effort,
        public readonly string $magentoGraphqlUrl,
        public readonly int $magentoTimeoutSeconds,
        /** @var list<string> */
        public readonly array $allowedOrigins,
        public readonly string $storeName,
        public readonly string $supportEmail,
        public readonly string $supportPhone,
        public readonly int $maxMessageChars,
        public readonly int $maxTurnsPerSession,
        public readonly int $rateLimitPerMinute,
        public readonly int $rateLimitPerDay,
        public readonly int $sessionTtlSeconds,
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
            anthropicApiKey: $env('ANTHROPIC_API_KEY'),
            model: $env('CLAUDE_MODEL', 'claude-opus-5-5'),
            effort: $env('CLAUDE_EFFORT', 'low'),
            magentoGraphqlUrl: $env('MAGENTO_GRAPHQL_URL'),
            magentoTimeoutSeconds: (int) $env('MAGENTO_TIMEOUT_SECONDS', '10'),
            allowedOrigins: array_values(array_filter(array_map('trim', explode(',', $env('ALLOWED_ORIGINS'))))),
            storeName: $env('STORE_NAME', 'BiteNXT'),
            supportEmail: $env('SUPPORT_EMAIL'),
            supportPhone: $env('SUPPORT_PHONE'),
            maxMessageChars: (int) $env('MAX_MESSAGE_CHARS', '2000'),
            maxTurnsPerSession: (int) $env('MAX_TURNS_PER_SESSION', '40'),
            rateLimitPerMinute: (int) $env('RATE_LIMIT_PER_MINUTE', '10'),
            rateLimitPerDay: (int) $env('RATE_LIMIT_PER_DAY', '200'),
            sessionTtlSeconds: (int) $env('SESSION_TTL_SECONDS', '86400'),
            handoffWebhookUrl: $env('HANDOFF_WEBHOOK_URL'),
            storageDir: rtrim($env('STORAGE_DIR', dirname(__DIR__) . '/var'), '/'),
            storageBackend: $env('STORAGE_BACKEND', 'file'),
            gcpProject: $env('GCP_PROJECT', $env('GOOGLE_CLOUD_PROJECT')),
            firestoreDatabase: $env('FIRESTORE_DATABASE', '(default)'),
            firestoreEmulatorHost: $env('FIRESTORE_EMULATOR_HOST'),
            logTarget: $env('LOG_TARGET', 'file'),
            promptCanary: $env('PROMPT_CANARY'),
            trustedProxyHops: (int) $env('TRUSTED_PROXY_HOPS', '0'),
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

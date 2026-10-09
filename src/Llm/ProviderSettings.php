<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Llm;

/**
 * One AI provider's settings, read from environment variables:
 *
 *   LLM_PROVIDERS=gemini,openrouter      order = primary, then fallbacks
 *   LLM_<NAME>_TYPE=anthropic | openai-compatible
 *   LLM_<NAME>_MODEL=...
 *   LLM_<NAME>_API_KEY=key1,key2         from Secret Manager; extra keys are tried on 401/403/429
 *   LLM_<NAME>_BASE_URL=...              openai-compatible only
 *   LLM_<NAME>_MAX_TOKENS_PARAM=max_tokens | max_completion_tokens   (openai-compatible only)
 *   LLM_<NAME>_EFFORT=low                anthropic only
 *   LLM_<NAME>_REASONING=off             openai-compatible only: off | low | medium | high, or empty to send nothing
 *                                        (OpenRouter's "reasoning" option; "off" keeps thinking models from spending
 *                                        the reply's token budget on hidden reasoning)
 *   LLM_<NAME>_DAILY_TOKEN_LIMIT=0       0 = no provider-specific limit
 *   LLM_<NAME>_TIMEOUT_SECONDS=60
 *
 * The names "gemini", "openrouter", "claude" and "openai" come with sensible defaults, so
 * usually only the model and API key need setting.
 */
final class ProviderSettings
{
    public const ANTHROPIC = 'anthropic';
    public const OPENAI_COMPATIBLE = 'openai-compatible';

    private const PRESETS = [
        'gemini' => ['type' => self::OPENAI_COMPATIBLE, 'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/', 'key_env' => 'GEMINI_API_KEY'],
        'claude' => ['type' => self::ANTHROPIC, 'model' => 'claude-opus-5-5', 'key_env' => 'ANTHROPIC_API_KEY', 'model_env' => 'CLAUDE_MODEL', 'effort_env' => 'CLAUDE_EFFORT'],
        'anthropic' => ['type' => self::ANTHROPIC, 'model' => 'claude-opus-5-5', 'key_env' => 'ANTHROPIC_API_KEY'],
        // OpenRouter: one key for many models (DeepSeek, Claude, GPT, ...); the model must support tool calling.
        // Default: DeepSeek V4.1 Flash, a low-cost model with tool calling, with reasoning off.
        'openrouter' => ['type' => self::OPENAI_COMPATIBLE, 'base_url' => 'https://openrouter.ai/api/v1/', 'key_env' => 'OPENROUTER_API_KEY',
            'model' => 'deepseek/deepseek-v4.1-flash', 'reasoning' => 'off'],
        'openai' => ['type' => self::OPENAI_COMPATIBLE, 'base_url' => 'https://api.openai.com/v1/', 'key_env' => 'OPENAI_API_KEY', 'max_tokens_param' => 'max_completion_tokens'],
    ];

    /** @param list<string> $apiKeys */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $model,
        public readonly array $apiKeys,
        public readonly string $baseUrl = '',
        public readonly string $effort = 'low',
        public readonly string $maxTokensParam = 'max_tokens',
        public readonly int $dailyTokenLimit = 0,
        public readonly int $timeoutSeconds = 60,
        public readonly string $reasoning = '',
    ) {
    }

    /**
     * @param callable(string, string=): string $env reads an environment variable with a default
     * @return list<self>
     */
    public static function fromEnvironment(callable $env): array
    {
        $names = array_values(array_filter(array_map(
            static fn ($n) => strtolower(trim($n)),
            explode(',', $env('LLM_PROVIDERS')),
        )));
        // Before multi-provider support, only ANTHROPIC_API_KEY existed.
        if ($names === [] && $env('ANTHROPIC_API_KEY') !== '') {
            $names = ['claude'];
        }

        $providers = [];
        foreach (array_unique($names) as $name) {
            $preset = self::PRESETS[$name] ?? [];
            $var = static fn (string $suffix) => 'LLM_' . strtoupper(preg_replace('/[^a-z0-9]/i', '_', $name)) . '_' . $suffix;
            $fromPreset = static fn (string $envKey) => isset($preset[$envKey]) ? $env($preset[$envKey]) : '';

            $keys = $env($var('API_KEY'), $fromPreset('key_env'));
            $providers[] = new self(
                name: $name,
                type: $env($var('TYPE'), $preset['type'] ?? self::OPENAI_COMPATIBLE),
                model: $env($var('MODEL'), $fromPreset('model_env') ?: ($preset['model'] ?? '')),
                apiKeys: array_values(array_filter(array_map('trim', explode(',', $keys)))),
                baseUrl: $env($var('BASE_URL'), $preset['base_url'] ?? ''),
                effort: $env($var('EFFORT'), $fromPreset('effort_env') ?: 'low'),
                maxTokensParam: $env($var('MAX_TOKENS_PARAM'), $preset['max_tokens_param'] ?? 'max_tokens'),
                dailyTokenLimit: (int) $env($var('DAILY_TOKEN_LIMIT'), '0'),
                timeoutSeconds: (int) $env($var('TIMEOUT_SECONDS'), '60'),
                reasoning: strtolower($env($var('REASONING'), $preset['reasoning'] ?? '')),
            );
        }

        return $providers;
    }

    /** Why this provider can't be used, or null if it can. Never includes the key itself. */
    public function problem(): ?string
    {
        return match (true) {
            !in_array($this->type, [self::ANTHROPIC, self::OPENAI_COMPATIBLE], true) => "unknown TYPE '{$this->type}'",
            $this->model === '' => 'MODEL is not set',
            $this->apiKeys === [] => 'API_KEY is not set',
            $this->type === self::OPENAI_COMPATIBLE && $this->baseUrl === '' => 'BASE_URL is not set',
            !in_array($this->reasoning, ['', 'off', 'low', 'medium', 'high'], true) => "unknown REASONING '{$this->reasoning}'",
            default => null,
        };
    }
}

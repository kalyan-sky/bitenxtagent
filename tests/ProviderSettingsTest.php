<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Tests;

use Bitenxt\SupportAgent\Llm\ProviderSettings;
use PHPUnit\Framework\TestCase;

final class ProviderSettingsTest extends TestCase
{
    private static function env(array $vars): callable
    {
        return static fn (string $key, string $default = '') => ($vars[$key] ?? '') !== '' ? $vars[$key] : $default;
    }

    public function testGeminiPrimaryClaudeFallbackFromPresets(): void
    {
        $providers = ProviderSettings::fromEnvironment(self::env([
            'LLM_PROVIDERS' => 'gemini, claude',
            'LLM_GEMINI_MODEL' => 'gemini-test-model',
            'LLM_GEMINI_API_KEY' => 'g-key-1,g-key-2',
            'LLM_CLAUDE_API_KEY' => 'sk-ant-x',
            'LLM_GEMINI_DAILY_TOKEN_LIMIT' => '3000000',
        ]));

        self::assertSame(['gemini', 'claude'], array_column($providers, 'name'));
        [$gemini, $claude] = $providers;
        self::assertSame(ProviderSettings::OPENAI_COMPATIBLE, $gemini->type);
        self::assertSame('https://generativelanguage.googleapis.com/v1beta/openai/', $gemini->baseUrl);
        self::assertSame(['g-key-1', 'g-key-2'], $gemini->apiKeys);
        self::assertSame(3000000, $gemini->dailyTokenLimit);
        self::assertSame(ProviderSettings::ANTHROPIC, $claude->type);
        self::assertSame('claude-opus-5-5', $claude->model);
        self::assertNull($gemini->problem());
        self::assertNull($claude->problem());
    }

    public function testOldAnthropicOnlySetupStillWorks(): void
    {
        $providers = ProviderSettings::fromEnvironment(self::env(['ANTHROPIC_API_KEY' => 'sk-ant-x', 'CLAUDE_EFFORT' => 'medium']));
        self::assertCount(1, $providers);
        self::assertSame('claude', $providers[0]->name);
        self::assertSame(['sk-ant-x'], $providers[0]->apiKeys);
        self::assertSame('medium', $providers[0]->effort);
    }

    public function testAnyOpenAiCompatibleProviderAndProblems(): void
    {
        [$groq, $gemini] = ProviderSettings::fromEnvironment(self::env([
            'LLM_PROVIDERS' => 'groq,gemini',
            'LLM_GROQ_BASE_URL' => 'https://api.groq.com/openai/v1/',
            'LLM_GROQ_MODEL' => 'some-model',
            'LLM_GROQ_API_KEY' => 'gk',
            'LLM_GEMINI_API_KEY' => 'g',
        ]));
        self::assertSame(ProviderSettings::OPENAI_COMPATIBLE, $groq->type);
        self::assertNull($groq->problem());
        self::assertSame('MODEL is not set', $gemini->problem(), 'Gemini has no default model: it must be chosen');
    }
}

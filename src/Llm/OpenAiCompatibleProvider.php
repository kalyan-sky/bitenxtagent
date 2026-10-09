<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Llm;

use Bitenxt\SupportAgent\Support\Http;

/**
 * Any provider that speaks the OpenAI Chat Completions API: Gemini (through
 * Google's OpenAI-compatible endpoint), OpenAI, Azure OpenAI, Mistral, Groq,
 * DeepSeek, OpenRouter, a self-hosted Ollama/vLLM, ...
 *
 * History is kept in Chat Completions format. The system prompt is added on
 * every call and never stored.
 */
final class OpenAiCompatibleProvider implements LlmProvider
{
    /** @var callable(string, list<string>, string, int): array{0: int, 1: string} */
    private $transport;

    /**
     * @param list<string> $apiKeys tried in order on rate-limit or key errors
     * @param string $maxTokensParam "max_tokens" (Gemini, most providers) or "max_completion_tokens" (newer OpenAI models)
     * @param callable|null $transport for tests: fn(url, headers, body, timeout) => [status, body]
     * @param string $reasoning "" sends nothing; "off" or an effort level sends OpenRouter's `reasoning` option
     */
    public function __construct(
        private readonly string $name,
        private readonly string $model,
        private readonly string $baseUrl,
        private readonly array $apiKeys,
        private readonly string $maxTokensParam = 'max_tokens',
        private readonly int $timeoutSeconds = 60,
        ?callable $transport = null,
        private readonly string $reasoning = '',
    ) {
        $this->transport = $transport ?? self::curlTransport(...);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function complete(string $system, array $tools, array $messages, int $maxOutputTokens): LlmResponse
    {
        $request = [
            'model' => $this->model,
            'messages' => array_merge([['role' => 'system', 'content' => $system]], $messages),
            'tools' => array_map(self::toolDefinition(...), $tools),
            'tool_choice' => 'auto',
            $this->maxTokensParam => $maxOutputTokens,
        ];
        if ($this->reasoning !== '') {
            $request['reasoning'] = $this->reasoning === 'off' ? ['enabled' => false] : ['effort' => $this->reasoning];
        }
        $body = json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $url = rtrim($this->baseUrl, '/') . '/chat/completions';

        $lastStatus = 0;
        foreach ($this->apiKeys as $key) {
            try {
                [$status, $raw] = ($this->transport)(
                    $url,
                    ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
                    $body,
                    $this->timeoutSeconds,
                );
            } catch (\Throwable $e) {
                throw new LlmUnavailableException($this->name . ': request failed: ' . $e->getMessage(), 0, $e);
            }

            if (in_array($status, [401, 403, 429], true)) {
                $lastStatus = $status; // this key is throttled or rejected: try the next key
                continue;
            }
            if ($status < 200 || $status >= 300) {
                throw new LlmUnavailableException($this->name . ': HTTP ' . $status . ' ' . self::errorMessage($raw));
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded) || !isset($decoded['choices'][0]['message'])) {
                throw new LlmUnavailableException($this->name . ': unexpected response shape');
            }

            return $this->toResponse($decoded);
        }

        throw new LlmUnavailableException($this->name . ': all API keys failed (last HTTP ' . $lastStatus . ')');
    }

    public function userMessage(string $text): array
    {
        return ['role' => 'user', 'content' => $text];
    }

    public function assistantMessage(string $text): array
    {
        return ['role' => 'assistant', 'content' => $text];
    }

    public function toolResultMessages(array $results): array
    {
        return array_map(static fn (array $r) => [
            'role' => 'tool',
            'tool_call_id' => $r['id'],
            'content' => $r['isError'] ? 'ERROR: ' . $r['content'] : $r['content'],
        ], $results);
    }

    /** @param array<string, mixed> $decoded */
    private function toResponse(array $decoded): LlmResponse
    {
        $choice = $decoded['choices'][0];
        $message = $choice['message'];
        $calls = [];
        $storedCalls = [];

        foreach ($message['tool_calls'] ?? [] as $call) {
            // Some providers omit tool-call IDs. Give each call one, and store
            // that same ID in history so the tool result can refer to it.
            $id = (string) ($call['id'] ?? '');
            if ($id === '') {
                $id = 'call_' . bin2hex(random_bytes(8));
            }
            $name = (string) ($call['function']['name'] ?? '');
            $arguments = (string) ($call['function']['arguments'] ?? '{}');
            $input = json_decode($arguments === '' ? '{}' : $arguments, true);

            $calls[] = new ToolCall($id, $name, is_array($input) ? $input : ['__invalid_json' => true]);
            $storedCalls[] = ['id' => $id, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => $arguments]];
        }

        $text = is_string($message['content'] ?? null) ? trim($message['content']) : '';
        $assistant = ['role' => 'assistant', 'content' => $text];
        if ($storedCalls !== []) {
            $assistant['tool_calls'] = $storedCalls;
        }

        // Gemini sometimes reports finish_reason "stop" alongside tool calls,
        // so tool calls decide the stop reason first.
        $stop = match (true) {
            $calls !== [] => LlmResponse::TOOL_USE,
            ($choice['finish_reason'] ?? '') === 'length' => LlmResponse::MAX_TOKENS,
            ($choice['finish_reason'] ?? '') === 'content_filter' => LlmResponse::REFUSAL,
            default => LlmResponse::END,
        };

        return new LlmResponse(
            stopReason: $stop,
            text: $text,
            toolCalls: $calls,
            assistantMessage: $assistant,
            inputTokens: (int) ($decoded['usage']['prompt_tokens'] ?? 0),
            outputTokens: (int) ($decoded['usage']['completion_tokens'] ?? 0),
        );
    }

    /**
     * Chat Completions tool format. `strict` and `additionalProperties` are
     * dropped: not every compatible provider accepts them (Gemini rejects
     * some JSON Schema keywords), and tool input is validated in our code anyway.
     *
     * @param array<string, mixed> $tool
     * @return array<string, mixed>
     */
    private static function toolDefinition(array $tool): array
    {
        return ['type' => 'function', 'function' => [
            'name' => $tool['name'],
            'description' => $tool['description'],
            'parameters' => self::stripUnsupported($tool['inputSchema']),
        ]];
    }

    private static function stripUnsupported(mixed $schema): mixed
    {
        if ($schema instanceof \stdClass) {
            return $schema;
        }
        if (!is_array($schema)) {
            return $schema;
        }
        unset($schema['additionalProperties'], $schema['strict']);
        foreach ($schema as $key => $value) {
            $schema[$key] = self::stripUnsupported($value);
        }

        return $schema;
    }

    private static function errorMessage(string $raw): string
    {
        $decoded = json_decode($raw, true);
        $error = $decoded['error'] ?? ($decoded[0]['error'] ?? null);

        return is_array($error) ? (string) ($error['message'] ?? '') : substr($raw, 0, 200);
    }

    /**
     * @param list<string> $headers
     * @return array{0: int, 1: string}
     */
    private static function curlTransport(string $url, array $headers, string $body, int $timeout): array
    {
        $ch = Http::handle($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        if ($raw === false) {
            throw new \RuntimeException($error);
        }

        return [$status, (string) $raw];
    }
}

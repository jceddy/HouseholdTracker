<?php

declare(strict_types=1);

namespace HouseholdTracker\Chat;

use RuntimeException;

/**
 * A minimal client for Fireworks AI's OpenAI-compatible chat completions endpoint
 * (https://docs.fireworks.ai/api-reference/post-chatcompletions). Uses cURL directly rather than
 * an HTTP client library, matching this project's otherwise-minimal dependency footprint.
 */
final class FireworksClient
{
    private const ENDPOINT = 'https://api.fireworks.ai/inference/v1/chat/completions';

    private string $apiKey;
    private string $model;
    /** @var callable(string, array<int, string>, string): array{status: int, body: string, headers?: array<string, string>} */
    private $transport;

    /**
     * @param callable(string, array<int, string>, string): array{status: int, body: string, headers?: array<string, string>}|null $transport
     *   Injectable HTTP transport for testing -- takes (url, headers, jsonBody), returns
     *   {status, body, headers}. Defaults to a real cURL POST.
     */
    public function __construct(string $apiKey, string $model, ?callable $transport = null)
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->transport = $transport ?? self::curlTransport(...);
    }

    /**
     * chatCompletion(messages, tools) - send one chat-completions request with the given
     * conversation and available tools (OpenAI function-calling "tools" array), auto-selecting
     * whether to call a tool. Returns the decoded response body, with `usage.cached_prompt_tokens`
     * added -- Fireworks reports prompt-cache hits via the `fireworks-cached-prompt-tokens`
     * response header rather than the JSON body, so it's merged in here to keep that detail out
     * of callers.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     * @return array<string, mixed>
     */
    public function chatCompletion(array $messages, array $tools): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'tools' => $tools,
            'tool_choice' => 'auto',
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Failed to encode Fireworks request payload.');
        }

        $response = ($this->transport)(
            self::ENDPOINT,
            ['Content-Type: application/json', 'Authorization: Bearer ' . $this->apiKey],
            $encoded
        );

        $decoded = json_decode($response['body'], true);

        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($decoded)) {
            $message = is_array($decoded) ? self::errorMessage($decoded['error'] ?? null) : $response['body'];
            $exceptionClass = $response['status'] === 402 ? FireworksInsufficientBalanceException::class : RuntimeException::class;
            throw new $exceptionClass("Fireworks request failed (HTTP {$response['status']}): {$message}");
        }

        if (isset($decoded['usage']) && is_array($decoded['usage'])) {
            $headers = $response['headers'] ?? [];
            $decoded['usage']['cached_prompt_tokens'] = (int) ($headers['fireworks-cached-prompt-tokens'] ?? 0);
        }

        return $decoded;
    }

    private static function errorMessage(mixed $error): string
    {
        if (is_array($error) && isset($error['message'])) {
            return (string) $error['message'];
        }
        if (is_string($error)) {
            return $error;
        }
        return json_encode($error) ?: 'unknown error';
    }

    /** @return array{status: int, body: string, headers: array<string, string>} */
    private static function curlTransport(string $url, array $headers, string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("Fireworks request failed: {$error}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr((string) $raw, 0, $headerSize);
        $responseBody = substr((string) $raw, $headerSize);

        return ['status' => $status, 'body' => $responseBody, 'headers' => self::parseHeaders($rawHeaders)];
    }

    /**
     * Parses a raw HTTP header block into a lowercased-name => value map. Takes the last
     * \r\n\r\n-delimited block, in case of an intermediate 100-continue response.
     *
     * @return array<string, string>
     */
    private static function parseHeaders(string $rawHeaders): array
    {
        $blocks = preg_split('/\r\n\r\n/', trim($rawHeaders));
        $lastBlock = end($blocks);
        if ($lastBlock === false) {
            return [];
        }

        $headers = [];
        foreach (explode("\r\n", $lastBlock) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
        return $headers;
    }
}

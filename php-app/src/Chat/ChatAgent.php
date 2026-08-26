<?php

declare(strict_types=1);

namespace HouseholdTracker\Chat;

use RuntimeException;
use Throwable;

/**
 * Runs a chat-completions tool-calling loop against a FireworksClient: sends the conversation
 * plus Tools::definitions(), and whenever the model responds with tool_calls, executes them via
 * Tools::call() and feeds the results back as role=tool messages, repeating until the model
 * replies with plain content (or the iteration cap is hit).
 */
final class ChatAgent
{
    private const MAX_TOOL_ITERATIONS = 12;

    private FireworksClient $client;

    public function __construct(FireworksClient $client)
    {
        $this->client = $client;
    }

    /**
     * run(messages) - given a conversation so far (OpenAI-style {role, content} messages, e.g.
     * starting with a system message and the user's latest message), runs the tool-calling loop
     * and returns the final assistant reply, the full updated message list (including any
     * assistant tool-call turns and tool-result turns -- pass this back in as the next request's
     * `messages` to continue the conversation), and the total token usage across every Fireworks
     * call this request made (one user-facing turn can trigger several, one per tool-call round).
     *
     * Throws ChatUsageException on any failure -- always carrying whatever usage was accumulated
     * before the failure, since those calls were still billed even though the request as a whole
     * didn't complete.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array{
     *     reply: string,
     *     messages: array<int, array<string, mixed>>,
     *     usage: array{prompt_tokens: int, cached_prompt_tokens: int, completion_tokens: int, total_tokens: int}
     * }
     */
    public function run(array $messages): array
    {
        $messages = array_values($messages);
        $tools = Tools::definitions();
        $usage = self::emptyUsage();

        for ($i = 0; $i < self::MAX_TOOL_ITERATIONS; $i++) {
            try {
                $response = $this->client->chatCompletion($messages, $tools);
            } catch (RuntimeException $e) {
                throw new ChatUsageException(
                    $e->getMessage(),
                    $usage,
                    insufficientBalance: $e instanceof FireworksInsufficientBalanceException,
                    previous: $e
                );
            }

            self::accumulate($usage, $response['usage'] ?? []);

            $message = $response['choices'][0]['message'] ?? null;
            if (!is_array($message)) {
                throw new ChatUsageException('Fireworks response had no message.', $usage);
            }

            $messages[] = $message;
            $toolCalls = $message['tool_calls'] ?? [];

            if (!is_array($toolCalls) || $toolCalls === []) {
                return ['reply' => (string) ($message['content'] ?? ''), 'messages' => $messages, 'usage' => $usage];
            }

            foreach ($toolCalls as $toolCall) {
                $messages[] = $this->executeToolCall($toolCall);
            }
        }

        throw new ChatUsageException(
            'Exceeded maximum tool-calling iterations (' . self::MAX_TOOL_ITERATIONS . ').',
            $usage,
            exceededToolIterations: true
        );
    }

    /** @return array{prompt_tokens: int, cached_prompt_tokens: int, completion_tokens: int, total_tokens: int} */
    private static function emptyUsage(): array
    {
        return ['prompt_tokens' => 0, 'cached_prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
    }

    /**
     * @param array{prompt_tokens: int, cached_prompt_tokens: int, completion_tokens: int, total_tokens: int} $usage
     * @param array<string, mixed> $responseUsage
     */
    private static function accumulate(array &$usage, array $responseUsage): void
    {
        foreach (['prompt_tokens', 'cached_prompt_tokens', 'completion_tokens', 'total_tokens'] as $key) {
            $usage[$key] += (int) ($responseUsage[$key] ?? 0);
        }
    }

    /**
     * @param array<string, mixed> $toolCall
     * @return array<string, mixed>
     */
    private function executeToolCall(array $toolCall): array
    {
        $name = (string) ($toolCall['function']['name'] ?? '');
        $rawArguments = $toolCall['function']['arguments'] ?? '{}';
        $arguments = json_decode((string) $rawArguments, true);
        if (!is_array($arguments)) {
            $arguments = [];
        }

        try {
            $result = Tools::call($name, $arguments);
        } catch (Throwable $e) {
            $result = ['error' => true, 'errorMessage' => $e->getMessage()];
        }

        return [
            'role' => 'tool',
            'tool_call_id' => (string) ($toolCall['id'] ?? ''),
            'content' => json_encode($result, JSON_UNESCAPED_SLASHES) ?: '{"error":true,"errorMessage":"failed to encode tool result"}',
        ];
    }
}

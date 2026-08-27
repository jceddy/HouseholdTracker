<?php

declare(strict_types=1);

namespace HouseholdTracker\Ledger;

use HouseholdTracker\Database\Connection;
use PDOException;

/**
 * Tracks LLM usage cost per authenticated user (see migrations/0004_add_chat_usage.sql) --
 * one row per POST /chat request. Recording is best-effort and never throws: /chat's actual
 * functionality must not depend on this write succeeding.
 */
final class Ledger
{
    /**
     * recordChatUsage(...) - best-effort insert of one /chat request's usage. Never throws --
     * swallows any insert failure, since /chat's response has already been computed by the time
     * this is called and shouldn't fail just because the ledger couldn't be written.
     *
     * @param array{prompt_tokens?: int, cached_prompt_tokens?: int, completion_tokens?: int, total_tokens?: int} $usage
     */
    public function recordChatUsage(int $userId, array $usage, float $costUsd, string $model, bool $success, ?string $errorMessage): void
    {
        try {
            $stmt = Connection::get()->prepare(
                'INSERT INTO chat_usage
                    (user_id, success, error_message, model, prompt_tokens, cached_prompt_tokens, completion_tokens, total_tokens, cost_usd)
                 VALUES
                    (:user_id, :success, :error_message, :model, :prompt_tokens, :cached_prompt_tokens, :completion_tokens, :total_tokens, :cost_usd)'
            );
            $stmt->execute([
                'user_id' => $userId,
                'success' => $success ? 1 : 0,
                'error_message' => $errorMessage,
                'model' => $model,
                'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
                'cached_prompt_tokens' => $usage['cached_prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
                'total_tokens' => $usage['total_tokens'] ?? 0,
                'cost_usd' => $costUsd,
            ]);
        } catch (PDOException) {
            // Ledger is auxiliary/track-only -- a write failure here must never surface as a
            // /chat failure.
        }
    }

    /**
     * usageForUser(userId) - this user's own lifetime usage totals across every recorded /chat
     * request (including failed ones, which may have zero cost).
     *
     * @return array{requestCount: int, totalUsageUsd: float, totalTokens: int, lastUsedAt: ?string}
     */
    public function usageForUser(int $userId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT
                COUNT(*) AS request_count,
                COALESCE(SUM(cost_usd), 0) AS total_usage_usd,
                COALESCE(SUM(total_tokens), 0) AS total_tokens,
                MAX(created_at) AS last_used_at
             FROM chat_usage
             WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();

        return [
            'requestCount' => (int) $row['request_count'],
            'totalUsageUsd' => (float) $row['total_usage_usd'],
            'totalTokens' => (int) $row['total_tokens'],
            'lastUsedAt' => $row['last_used_at'],
        ];
    }
}

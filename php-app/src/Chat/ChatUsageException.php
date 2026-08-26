<?php

declare(strict_types=1);

namespace HouseholdTracker\Chat;

use RuntimeException;
use Throwable;

/**
 * Thrown by ChatAgent::run() on any failure. Always carries the token usage accumulated across
 * whatever Fireworks calls succeeded before the failure -- those calls were still billed by
 * Fireworks even though the overall chat request didn't complete (e.g. a later call in a
 * multi-tool-call loop fails, or the iteration cap is hit), so callers tracking spend need this
 * on the error path too, not just on success.
 */
final class ChatUsageException extends RuntimeException
{
    /**
     * @param array{prompt_tokens: int, cached_prompt_tokens: int, completion_tokens: int, total_tokens: int} $usage
     */
    public function __construct(
        string $message,
        public readonly array $usage,
        public readonly bool $insufficientBalance = false,
        public readonly bool $exceededToolIterations = false,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}

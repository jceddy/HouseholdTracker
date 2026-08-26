<?php

declare(strict_types=1);

namespace HouseholdTracker\Chat;

/**
 * Computes the USD cost of a chat-completion usage total from Fireworks' per-1M-token pricing.
 * Rates are passed in per-model (see HouseholdTracker\Chat\ModelCatalog, which builds one of
 * these per model in its catalog) rather than hardcoded here.
 */
final class CostCalculator
{
    private float $inputPricePerMillion;
    private float $cachedInputPricePerMillion;
    private float $outputPricePerMillion;

    public function __construct(float $inputPricePerMillion, float $cachedInputPricePerMillion, float $outputPricePerMillion)
    {
        $this->inputPricePerMillion = $inputPricePerMillion;
        $this->cachedInputPricePerMillion = $cachedInputPricePerMillion;
        $this->outputPricePerMillion = $outputPricePerMillion;
    }

    /**
     * costUsd(usage) - the dollar cost of a {prompt_tokens, cached_prompt_tokens,
     * completion_tokens} usage total. prompt_tokens is the *total* input tokens (cached +
     * uncached combined, matching Fireworks' `usage.prompt_tokens`); cached_prompt_tokens is
     * billed at the cached rate and the remainder at the regular input rate.
     *
     * @param array{prompt_tokens?: int, cached_prompt_tokens?: int, completion_tokens?: int} $usage
     */
    public function costUsd(array $usage): float
    {
        $promptTokens = $usage['prompt_tokens'] ?? 0;
        $cachedTokens = min($usage['cached_prompt_tokens'] ?? 0, $promptTokens);
        $uncachedTokens = $promptTokens - $cachedTokens;
        $completionTokens = $usage['completion_tokens'] ?? 0;

        return ($uncachedTokens / 1_000_000) * $this->inputPricePerMillion
            + ($cachedTokens / 1_000_000) * $this->cachedInputPricePerMillion
            + ($completionTokens / 1_000_000) * $this->outputPricePerMillion;
    }
}

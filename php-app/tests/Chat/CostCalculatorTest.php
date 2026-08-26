<?php

declare(strict_types=1);

namespace HouseholdTracker\Tests\Chat;

use HouseholdTracker\Chat\CostCalculator;
use PHPUnit\Framework\TestCase;

final class CostCalculatorTest extends TestCase
{
    public function testSplitsCachedAndUncachedPromptTokensAtDifferentRates(): void
    {
        $calculator = new CostCalculator(inputPricePerMillion: 1.0, cachedInputPricePerMillion: 0.1, outputPricePerMillion: 2.0);

        $cost = $calculator->costUsd([
            'prompt_tokens' => 1_000_000,
            'cached_prompt_tokens' => 400_000,
            'completion_tokens' => 500_000,
        ]);

        // 600k uncached @ $1/M + 400k cached @ $0.1/M + 500k output @ $2/M
        self::assertEqualsWithDelta(0.6 + 0.04 + 1.0, $cost, 0.0000001);
    }

    public function testMissingUsageFieldsDefaultToZero(): void
    {
        $calculator = new CostCalculator(1.0, 0.1, 2.0);

        self::assertSame(0.0, $calculator->costUsd([]));
    }

    public function testCachedTokensNeverExceedPromptTokens(): void
    {
        $calculator = new CostCalculator(inputPricePerMillion: 1.0, cachedInputPricePerMillion: 0.1, outputPricePerMillion: 2.0);

        // A cached_prompt_tokens larger than prompt_tokens (shouldn't happen, but shouldn't
        // produce a negative "uncached" count either) is clamped to prompt_tokens.
        $cost = $calculator->costUsd(['prompt_tokens' => 100, 'cached_prompt_tokens' => 1_000_000, 'completion_tokens' => 0]);

        self::assertEqualsWithDelta((100 / 1_000_000) * 0.1, $cost, 0.0000001);
    }
}

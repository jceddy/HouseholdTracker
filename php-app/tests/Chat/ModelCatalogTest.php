<?php

declare(strict_types=1);

namespace HouseholdTracker\Tests\Chat;

use HouseholdTracker\Chat\ModelCatalog;
use PHPUnit\Framework\TestCase;

final class ModelCatalogTest extends TestCase
{
    public function testDefaultKeyIsInCatalog(): void
    {
        self::assertTrue(ModelCatalog::has(ModelCatalog::DEFAULT_KEY));
        self::assertContains(ModelCatalog::DEFAULT_KEY, ModelCatalog::keys());
    }

    public function testUnknownKeyIsNotInCatalog(): void
    {
        self::assertFalse(ModelCatalog::has('not-a-real-model'));
    }

    public function testPricingReturnsACostCalculator(): void
    {
        $pricing = ModelCatalog::pricing(ModelCatalog::DEFAULT_KEY);

        self::assertGreaterThan(0.0, $pricing->costUsd(['prompt_tokens' => 1_000_000, 'completion_tokens' => 0]));
    }
}

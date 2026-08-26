<?php

declare(strict_types=1);

namespace HouseholdTracker\Chat;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The Fireworks-hosted models POST /chat can choose between via its optional `model` request
 * field (one of these keys). Replace the placeholder entry below with the models this app
 * actually wants to offer, and their current published rates from
 * https://fireworks.ai/pricing.
 *
 * Each model's pricing is a list of tiers, letting a Fireworks-announced rate change be
 * pre-populated ahead of time and picked up automatically once it takes effect, rather than
 * needing a same-day deploy: pricing() returns whichever tier has the latest `effectiveAt` that
 * isn't in the future (comparing against UTC "now" by default). Exactly one tier per model should
 * have `effectiveAt => null` -- the baseline rate applied before any dated tier's time comes, and
 * whenever the list would otherwise be empty. Tiers don't need to be listed in chronological
 * order -- pricing() sorts them itself. Update by hand -- nothing here looks Fireworks' published
 * rates up automatically.
 */
final class ModelCatalog
{
    public const DEFAULT_KEY = 'default';

    private const MODELS = [
        // Replace with a real Fireworks-hosted model id and its current published per-1M-token
        // pricing (see the class docblock above) before relying on this in production.
        'default' => [
            'fireworksModel' => 'accounts/fireworks/models/llama-v3p1-8b-instruct',
            'pricingTiers' => [
                [
                    'effectiveAt' => null,
                    'inputPricePerMillion' => 0.20,
                    'cachedInputPricePerMillion' => 0.20,
                    'outputPricePerMillion' => 0.20,
                ],
            ],
        ],
    ];

    public static function has(string $key): bool
    {
        return isset(self::MODELS[$key]);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::MODELS);
    }

    public static function fireworksModel(string $key): string
    {
        return self::MODELS[$key]['fireworksModel'];
    }

    /**
     * pricing(key, at) - the CostCalculator for whichever of key's pricing tiers is in effect at
     * `at` (defaults to now, UTC). Pass an explicit `at` to price a specific past/future moment.
     */
    public static function pricing(string $key, ?DateTimeImmutable $at = null): CostCalculator
    {
        $at ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $tier = self::selectTier(self::MODELS[$key]['pricingTiers'], $at);

        return new CostCalculator(
            $tier['inputPricePerMillion'],
            $tier['cachedInputPricePerMillion'],
            $tier['outputPricePerMillion']
        );
    }

    /** @param list<array{effectiveAt: ?string}> $tiers */
    private static function selectTier(array $tiers, DateTimeImmutable $at): array
    {
        usort($tiers, fn (array $a, array $b) => self::tierTimestamp($a) <=> self::tierTimestamp($b));

        $applicable = $tiers[0];
        $atTimestamp = $at->getTimestamp();
        foreach ($tiers as $tier) {
            if (self::tierTimestamp($tier) <= $atTimestamp) {
                $applicable = $tier;
            }
        }

        return $applicable;
    }

    /** A tier with no effectiveAt is the baseline -- always in effect, so it sorts first. */
    private static function tierTimestamp(array $tier): int
    {
        return $tier['effectiveAt'] === null ? PHP_INT_MIN : (new DateTimeImmutable($tier['effectiveAt']))->getTimestamp();
    }
}

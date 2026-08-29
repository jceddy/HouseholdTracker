<?php

declare(strict_types=1);

namespace HouseholdTracker\Tests\Household;

use HouseholdTracker\Household\RecurrenceCalculator;
use PHPUnit\Framework\TestCase;

final class RecurrenceCalculatorTest extends TestCase
{
    public function testDailyAddsExactDayCount(): void
    {
        $result = RecurrenceCalculator::advance(new \DateTimeImmutable('2026-03-01'), 'daily', 15);

        self::assertSame('2026-03-16', $result->format('Y-m-d'));
    }

    public function testWeeklyMultipliesByInterval(): void
    {
        $result = RecurrenceCalculator::advance(new \DateTimeImmutable('2026-03-02'), 'weekly', 2);

        self::assertSame('2026-03-16', $result->format('Y-m-d'));
    }

    public function testMonthlyClampsShorterTargetMonth(): void
    {
        // Jan 31 + 1 month must land on Feb 28 in a non-leap year, not
        // overflow into March the way a naive "+1 month" DateTime::modify()
        // would (Jan 31 -> Mar 3).
        $result = RecurrenceCalculator::advance(new \DateTimeImmutable('2026-01-31'), 'monthly', 1);

        self::assertSame('2026-02-28', $result->format('Y-m-d'));
    }

    public function testMonthlyClampsToLeapDayInLeapYear(): void
    {
        $result = RecurrenceCalculator::advance(new \DateTimeImmutable('2027-12-31'), 'monthly', 2);

        self::assertSame('2028-02-29', $result->format('Y-m-d'));
    }

    public function testMonthlyDoesNotSpringBackAfterAClamp(): void
    {
        // A deliberate, documented consequence (see RecurrenceCalculator's
        // own docblock): once a month-end date clamps down (Jan 31 -> Feb
        // 28), the *next* advance runs from that clamped date, so it stays
        // clamped (Mar 28) rather than re-expanding back to the 31st.
        $first = RecurrenceCalculator::advance(new \DateTimeImmutable('2026-01-31'), 'monthly', 1);
        $second = RecurrenceCalculator::advance($first, 'monthly', 1);

        self::assertSame('2026-02-28', $first->format('Y-m-d'));
        self::assertSame('2026-03-28', $second->format('Y-m-d'));
    }

    public function testMonthlyPreservesDayWhenTargetMonthIsLongEnough(): void
    {
        $result = RecurrenceCalculator::advance(new \DateTimeImmutable('2026-01-31'), 'monthly', 2);

        self::assertSame('2026-03-31', $result->format('Y-m-d'));
    }

    public function testMonthlyCarriesOverIntoNextYear(): void
    {
        $result = RecurrenceCalculator::advance(new \DateTimeImmutable('2026-11-15'), 'monthly', 3);

        self::assertSame('2027-02-15', $result->format('Y-m-d'));
    }

    public function testAnnualClampsLeapDayInNonLeapTargetYear(): void
    {
        $result = RecurrenceCalculator::advance(new \DateTimeImmutable('2024-02-29'), 'annual', 1);

        self::assertSame('2025-02-28', $result->format('Y-m-d'));
    }

    public function testAnnualPreservesLeapDayWhenTargetYearIsAlsoLeap(): void
    {
        $result = RecurrenceCalculator::advance(new \DateTimeImmutable('2024-02-29'), 'annual', 4);

        self::assertSame('2028-02-29', $result->format('Y-m-d'));
    }

    public function testRejectsNonPositiveInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RecurrenceCalculator::advance(new \DateTimeImmutable('2026-01-01'), 'daily', 0);
    }

    public function testRejectsUnknownFrequency(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RecurrenceCalculator::advance(new \DateTimeImmutable('2026-01-01'), 'fortnightly', 1);
    }
}

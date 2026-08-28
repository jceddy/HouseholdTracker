<?php

declare(strict_types=1);

namespace HouseholdTracker\Household;

/**
 * RecurrenceCalculator - calendar-correct "advance the due date by one
 * recurrence interval" arithmetic (issue #12). Deliberately NOT a naive
 * day-count add for monthly/annual: months vary in length and leap years
 * exist, so "+1 month" from Jan 31 must land on Feb 28 (or 29), not spill
 * into March the way a raw +30-days would.
 *
 * Always advances from the task's own previous scheduled due date, never
 * from whenever it was actually completed -- a chore due every Monday stays
 * anchored to Monday even if it's occasionally done late, rather than
 * drifting to whatever day it happened to get done (see TaskService::
 * completeTask()'s own docblock -- this was an explicit open question in
 * issue #12).
 *
 * One deliberate consequence, worth naming since it's easy to mistake for a
 * bug: because each advance clamps from whatever the *current* next_due_at
 * literally is (not a remembered "original" day-of-month), a monthly/annual
 * task anchored on a month-end date does not spring back once a shorter
 * month has clamped it. E.g. a "31st of every month" task clamps to Feb 28,
 * and *stays* on the 28th every month after that (Mar 28, not Mar 31) --
 * it never re-expands back to the 31st. Preserving the original
 * day-of-month forever would need its own anchor column; not worth the
 * complexity for v1 (see issue #12).
 */
final class RecurrenceCalculator
{
    private const FREQUENCIES = ['daily', 'weekly', 'monthly', 'annual'];

    public static function advance(\DateTimeImmutable $dueAt, string $frequency, int $interval): \DateTimeImmutable
    {
        if ($interval < 1) {
            throw new \InvalidArgumentException('recurrence_interval must be a positive integer.');
        }

        return match ($frequency) {
            'daily' => $dueAt->modify("+{$interval} days"),
            'weekly' => $dueAt->modify('+' . ($interval * 7) . ' days'),
            'monthly' => self::addCalendarMonths($dueAt, $interval),
            'annual' => self::addCalendarMonths($dueAt, $interval * 12),
            default => throw new \InvalidArgumentException(
                'recurrence_frequency must be one of: ' . implode(', ', self::FREQUENCIES) . '.'
            ),
        };
    }

    /**
     * addCalendarMonths(...) - PHP's own DateTime::modify('+1 month') overflows
     * past short months (Jan 31 + 1 month = Mar 3, not Feb 28), so this clamps
     * the day-of-month to the target month's actual length instead.
     */
    private static function addCalendarMonths(\DateTimeImmutable $dueAt, int $months): \DateTimeImmutable
    {
        $year = (int) $dueAt->format('Y');
        $month = (int) $dueAt->format('n');
        $day = (int) $dueAt->format('j');

        $totalMonths = ($year * 12 + ($month - 1)) + $months;
        $targetYear = intdiv($totalMonths, 12);
        $targetMonth = ($totalMonths % 12) + 1;

        $daysInTargetMonth = (int) $dueAt->setDate($targetYear, $targetMonth, 1)->format('t');

        return $dueAt->setDate($targetYear, $targetMonth, min($day, $daysInTargetMonth));
    }
}

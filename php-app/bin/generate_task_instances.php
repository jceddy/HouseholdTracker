#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use HouseholdTracker\Household\RecurrenceCalculator;
use HouseholdTracker\Repository\HouseholdTaskInstanceRepository;
use HouseholdTracker\Repository\HouseholdTaskRepository;

// Meant to run once a day via cron (see "Task/chore tracking" in
// php-app/README.md for the cPanel setup). Idempotent -- safe to run
// more than once on the same day, or to miss a day and catch up on the
// next run.

// How many days ahead to keep a recurring task's upcoming occurrences
// populated. Small on purpose: this is a rolling window regenerated daily,
// not a one-time schedule dump -- a short lookahead still gives plenty of
// advance notice for daily/weekly chores while keeping the instance count
// per task small for the rare very-short-interval one.
const LOOKAHEAD_DAYS = 7;

// How long a pending instance nobody ever completed, or a resolved one
// (completed or skipped), sticks around before the cleanup pass below
// removes it.
const RETENTION_DAYS = 90;

$tasks = new HouseholdTaskRepository();
$instances = new HouseholdTaskInstanceRepository();

$today = new DateTimeImmutable('today');
$horizon = $today->modify('+' . LOOKAHEAD_DAYS . ' days');

$generated = 0;
foreach ($tasks->listAllRecurring() as $task) {
    $latest = $instances->findLatestForTask((int) $task['id']);
    // Every task gets its first instance(s) synchronously at creation time
    // (TaskService::createTask()), so this should always find one -- but
    // fall back to the definition's own start_date rather than skip the
    // task outright if it somehow doesn't.
    $dueAt = new DateTimeImmutable((string) ($latest['due_at'] ?? $task['start_date']));

    // 'anyone' mode (including 0/1 assignees) generates one shared instance
    // per occurrence (assigned_to_user_id null); 'everyone' mode generates
    // one per assignee, each their own copy to complete -- see
    // TaskService's and HouseholdTaskInstanceRepository's own docblocks.
    $assigneeIds = $task['assignment_mode'] === 'everyone' ? $tasks->listAssigneeIds((int) $task['id']) : [null];

    while (true) {
        $dueAt = RecurrenceCalculator::advance($dueAt, (string) $task['recurrence_frequency'], (int) $task['recurrence_interval']);
        if ($dueAt > $horizon) {
            break;
        }

        $dueAtString = $dueAt->format('Y-m-d');
        foreach ($assigneeIds as $assigneeId) {
            if (!$instances->existsForTaskAndDate((int) $task['id'], $dueAtString, $assigneeId)) {
                $instances->create((int) $task['id'], $dueAtString, $assigneeId);
                $generated++;
            }
        }
    }
}
echo "Generated {$generated} new task instance(s).\n";

$purgedResolved = $instances->purgeResolvedOlderThan(RETENTION_DAYS);
$purgedExpired = $instances->purgeExpiredPendingOlderThan(RETENTION_DAYS);
echo "Purged {$purgedResolved} old completed/skipped instance(s) and {$purgedExpired} old never-completed instance(s) (older than " . RETENTION_DAYS . " days).\n";

$purgedOrphans = $tasks->deleteOrphanedOneOffTasks();
echo "Deleted {$purgedOrphans} orphaned one-off task definition(s) with no remaining instances.\n";

#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use HouseholdTracker\Repository\HouseholdMessageRepository;

// Meant to run once a day via cron (see "Cron setup" in "Household chat" in
// php-app/README.md for the cPanel setup). Idempotent -- safe to run more
// than once on the same day, or to miss a day and catch up on the next
// run, the same as bin/generate_task_instances.php.

// How long a chat message sticks around before this deletes it. A message
// has no "resolved" state to weigh the way household_task_instances does
// -- age alone is the retention rule.
const RETENTION_DAYS = 7;

$messages = new HouseholdMessageRepository();

$deleted = $messages->deleteOlderThan(RETENTION_DAYS);
echo "Deleted {$deleted} chat message(s) older than " . RETENTION_DAYS . " days.\n";

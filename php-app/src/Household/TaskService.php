<?php

declare(strict_types=1);

namespace HouseholdTracker\Household;

use HouseholdTracker\Repository\HouseholdMemberRepository;
use HouseholdTracker\Repository\HouseholdTaskInstanceRepository;
use HouseholdTracker\Repository\HouseholdTaskRepository;

/**
 * Household task/chore tracking (issue #12): one-off tasks and recurring
 * chores (daily/weekly/monthly/annual, on an N-interval), assignable to any
 * number of household members, plus open-ended one-off tasks with no due
 * date at all (issue #12's own follow-up, migration `0011`) -- see
 * "Open-ended tasks" below. Tasks are a shared household resource, not
 * per-user content, same permission model as pets: any member can create/
 * edit/delete/complete any task, regardless of who created or is assigned
 * it (in `"everyone"` mode this means, e.g., any member can complete a
 * *different* assignee's own instance copy on their behalf).
 *
 * Reworked into a definition (HouseholdTaskRepository)+instances
 * (HouseholdTaskInstanceRepository) split by a follow-up to #12 -- see
 * database/migrations/0009_add_household_task_instances.sql and
 * "Task/chore tracking" in php-app/README.md for why. Every method here
 * that used to take a task_id now takes an instance_id instead (an
 * instance's own id), except createTask() (there's nothing to identify yet)
 * and, implicitly, updateTask() -- which edits both the instance being
 * looked at *and* its parent definition in one call, since from the UI's
 * side there's just one "task" being edited, not two separate objects.
 *
 * Multiple assignees (#12's own follow-up, migration `0010`) add
 * `assignment_mode`, deciding what 2+ assignees on a task means:
 *   - 'anyone' (the default): one shared instance per occurrence -- whoever
 *     completes it first completes it for everyone assigned.
 *   - 'everyone': each assignee gets their own instance row for the same
 *     occurrence and must complete their own copy; the others are unaffected.
 * A task's assignee list is edited as a whole (replaceAssignees()), the
 * same way its other fields are -- there's no separate add/remove-one-
 * assignee endpoint.
 *
 * **Open-ended tasks** (#12's own follow-up, migration `0011`): a one-off
 * task ("put the new latch on the back gate") doesn't always have a real
 * deadline. Omitting `due_at` on a *one-off* task now leaves its instance's
 * `due_at` NULL instead of defaulting to today -- a recurring task's
 * occurrences still always need a real anchor date, so `due_at` still
 * defaults to today there when omitted (see validateDueAt()). An
 * open-ended task (one-off, `due_at` NULL) gets a `priority`
 * ('low'/'medium'/'high'/'critical', defaulting to 'medium' if not given)
 * so it can be triaged -- HouseholdTaskInstanceRepository's list methods
 * sort every open-ended instance ahead of every dated one, highest
 * priority first ("bubble to the top... in reverse-priority order"). A
 * dated or recurring task can have a priority set too (not rejected), it
 * just isn't used to reorder anything outside the no-deadline group.
 *
 * **Skipping a recurring occurrence** (#12's own follow-up, migration
 * `0012`): completeInstance() means it happened; deleteInstance() removes
 * the row entirely; skipInstance() is the third option, for a recurring
 * chore's occurrence that isn't happening this time but is worth a reason
 * on record ("didn't walk the dog -- there was a tornado") -- status
 * `'skipped'`, with a *required* note. One-off tasks can't be skipped
 * (delete instead); see skipInstance()'s own docblock.
 *
 * **Viewing finished tasks** (#12's own follow-up): a completed or skipped
 * instance drops off listTasks()/listMyTasks() the moment it's no longer
 * pending, same as always -- listFinishedToday() is the household Tasks
 * tab's separate window into what actually got resolved today (either
 * way), so that history isn't just invisible once acted on.
 *
 * **Notes on a task** (#12's own follow-up): `notes` isn't only a
 * completion/skip explanation any more -- createTask()/updateTask() can
 * set it directly on a still-pending instance too, for anything worth
 * jotting down about this occurrence ("need to buy dish soap first").
 * completeInstance() leaves it alone when no new note is given (see
 * HouseholdTaskInstanceRepository::markDone()'s own docblock) rather than
 * clearing it on a plain "mark done" click; skipInstance()'s note always
 * overwrites, since the skip reason is what matters most from then on.
 */
final class TaskService
{
    private const RECURRENCE_FREQUENCIES = ['daily', 'weekly', 'monthly', 'annual'];
    private const ASSIGNMENT_MODES = ['anyone', 'everyone'];
    private const PRIORITIES = ['low', 'medium', 'high', 'critical'];
    private const MAX_RECURRENCE_INTERVAL = 1000;
    private const MAX_NOTES_LENGTH = 2000;

    public function __construct(
        private readonly HouseholdMemberRepository $members,
        private readonly HouseholdTaskRepository $tasks,
        private readonly HouseholdTaskInstanceRepository $instances,
    ) {
    }

    public function listTasks(int $callerId, int $householdId): array
    {
        $this->requireMember($householdId, $callerId);

        return $this->attachAssignees($this->instances->listForHousehold($householdId));
    }

    /**
     * listFinishedToday(...) - the household Tasks tab's "Show finished
     * today" list: every instance resolved today, completed or skipped
     * alike -- see HouseholdTaskInstanceRepository::listFinishedToday()'s
     * own docblock.
     */
    public function listFinishedToday(int $callerId, int $householdId): array
    {
        $this->requireMember($householdId, $callerId);

        return $this->attachAssignees($this->instances->listFinishedToday($householdId));
    }

    /**
     * listMyTasks(...) - the "My Tasks" view: every pending instance
     * assigned to this user across every household they belong to, not
     * scoped to one household. No membership check needed here beyond what
     * HouseholdTaskInstanceRepository::listAssignedToUser() already
     * enforces via its own join.
     */
    public function listMyTasks(int $userId): array
    {
        return $this->attachAssignees($this->instances->listAssignedToUser($userId));
    }

    /**
     * createTask(...) - returns an *array of* created instance rows rather
     * than a single one: 'anyone' mode (the default, and the only
     * meaningful choice for 0/1 assignees) creates one shared instance, but
     * 'everyone' mode creates one per assignee for this same occurrence
     * (see HouseholdTaskInstanceRepository::create()'s $assignedToUserId).
     *
     * @param array<int> $assignedToUserIds
     */
    public function createTask(
        int $callerId,
        int $householdId,
        string $title,
        ?string $description,
        array $assignedToUserIds,
        ?string $assignmentMode,
        ?string $recurrenceFrequency,
        ?int $recurrenceInterval,
        ?string $dueAt,
        ?string $priority,
        ?string $notes = null
    ): array {
        $this->requireMember($householdId, $callerId);
        [$title, $description] = $this->validateTitleAndDescription($title, $description);
        $assignmentMode = $this->validateAssignmentMode($assignmentMode);
        $assignedToUserIds = $this->validateAssignees($householdId, $assignedToUserIds, $assignmentMode);
        [$recurrenceFrequency, $recurrenceInterval] = $this->validateRecurrence($recurrenceFrequency, $recurrenceInterval);
        $dueAt = $this->validateDueAt($dueAt, $recurrenceFrequency !== null);
        $priority = $this->validatePriority($priority, $recurrenceFrequency === null && $dueAt === null);
        $notes = $this->validateNotes($notes);
        // start_date is NOT NULL on the definition even for an open-ended
        // task (due_at NULL) -- see HouseholdTaskRepository's own docblock,
        // its value is simply never read for a task cron doesn't process.
        $startDate = $dueAt ?? (new \DateTimeImmutable('today'))->format('Y-m-d');

        $task = $this->tasks->create($householdId, $callerId, $title, $description, $assignmentMode, $priority, $recurrenceFrequency, $recurrenceInterval, $startDate);
        $this->tasks->replaceAssignees((int) $task['id'], $assignedToUserIds);

        $instances = $assignmentMode === 'everyone'
            ? array_map(fn (int $userId): array => $this->instances->create((int) $task['id'], $dueAt, $userId, $notes), $assignedToUserIds)
            : [$this->instances->create((int) $task['id'], $dueAt, null, $notes)];

        return $this->attachAssignees(array_map(
            fn (array $instance): array => $this->instances->findByIdWithTaskInfo((int) $instance['id']),
            $instances
        ));
    }

    /**
     * updateTask(...) - edits the parent definition's title/description/
     * assignees/mode/priority/recurrence, *and* moves this specific
     * instance's own due date. Doesn't touch the definition's start_date
     * (see HouseholdTaskRepository::update()'s own docblock) or any other
     * instance -- a recurring task's already-generated future occurrences
     * keep whatever dates cron gave them, and changing the assignee list
     * here doesn't retroactively create or delete instances for the
     * newly-added/removed assignees, only affect what cron generates next.
     *
     * @param array<int> $assignedToUserIds
     */
    public function updateTask(
        int $callerId,
        int $instanceId,
        string $title,
        ?string $description,
        array $assignedToUserIds,
        ?string $assignmentMode,
        ?string $recurrenceFrequency,
        ?int $recurrenceInterval,
        ?string $dueAt,
        ?string $priority,
        ?string $notes = null
    ): array {
        $instance = $this->requireMemberForInstance($callerId, $instanceId);
        $task = $this->tasks->findById((int) $instance['task_id']);
        [$title, $description] = $this->validateTitleAndDescription($title, $description);
        $assignmentMode = $this->validateAssignmentMode($assignmentMode);
        $assignedToUserIds = $this->validateAssignees((int) $task['household_id'], $assignedToUserIds, $assignmentMode);
        [$recurrenceFrequency, $recurrenceInterval] = $this->validateRecurrence($recurrenceFrequency, $recurrenceInterval);
        $dueAt = $this->validateDueAt($dueAt, $recurrenceFrequency !== null);
        $priority = $this->validatePriority($priority, $recurrenceFrequency === null && $dueAt === null);
        $notes = $this->validateNotes($notes);

        $this->tasks->update((int) $task['id'], $title, $description, $assignmentMode, $priority, $recurrenceFrequency, $recurrenceInterval);
        $this->tasks->replaceAssignees((int) $task['id'], $assignedToUserIds);
        $this->instances->updateDueAt($instanceId, $dueAt);
        // updateNotes(), not markDone()'s preserve-if-omitted COALESCE --
        // this is an explicit edit, so an omitted/blank notes field here
        // really does mean "clear it," the same as clearing the
        // description would.
        $this->instances->updateNotes($instanceId, $notes);

        return $this->attachAssignees([$this->instances->findByIdWithTaskInfo($instanceId)])[0];
    }

    /**
     * deleteInstance(...) - deletes this occurrence first, then cascades to
     * the whole definition only if it's one-off (never for a recurring
     * task, regardless of instance count) *and* has no instances left --
     * covers both a single-assignee one-off (its one and only instance) and
     * an 'everyone'-mode one-off (each assignee's own copy needs deleting
     * before the definition goes). A recurring task's instance is just one
     * occurrence among others (existing and future), so only that row goes
     * -- "skip this occurrence" -- leaving the definition and its other
     * instances alone.
     */
    public function deleteInstance(int $callerId, int $instanceId): void
    {
        $instance = $this->requireMemberForInstance($callerId, $instanceId);
        $task = $this->tasks->findById((int) $instance['task_id']);

        $this->instances->delete($instanceId);

        if ($task['recurrence_frequency'] === null && $this->instances->countForTask((int) $task['id']) === 0) {
            $this->tasks->delete((int) $task['id']);
        }
    }

    /**
     * completeInstance(...) - $notes here is optional and, if omitted,
     * preserves whatever note the task already had (see
     * HouseholdTaskInstanceRepository::markDone()'s own docblock) rather
     * than clearing it -- completing a task is usually just a click, and
     * shouldn't silently wipe out a note someone wrote while it was still
     * pending.
     */
    public function completeInstance(int $callerId, int $instanceId, ?string $notes): array
    {
        $instance = $this->requireMemberForInstance($callerId, $instanceId);
        $notes = $this->validateNotes($notes);

        $this->instances->markDone($instanceId, $callerId, $notes);

        return $this->attachAssignees([$this->instances->findByIdWithTaskInfo($instanceId)])[0];
    }

    /**
     * skipInstance(...) - "this occurrence isn't happening" for a recurring
     * chore ("didn't walk the dog -- there was a tornado"), distinct from
     * completeInstance() (it wasn't done) and deleteInstance() (it still
     * happened, on record, just not by doing the chore -- deleting loses
     * that entirely). Recurring-only: a one-off task has nothing recurring
     * to skip *to* the next occurrence of, and already has delete for
     * "get rid of this." $notes is required and non-empty here, unlike
     * completeInstance()'s optional one -- a skip without a reason is just
     * a delete that leaves a row behind.
     */
    public function skipInstance(int $callerId, int $instanceId, string $notes): array
    {
        $instance = $this->requireMemberForInstance($callerId, $instanceId);
        $task = $this->tasks->findById((int) $instance['task_id']);

        if ($task['recurrence_frequency'] === null) {
            throw new \InvalidArgumentException('Only a recurring task can be skipped -- delete a one-off task instead.');
        }

        $notes = $this->validateNotes($notes);
        if ($notes === null) {
            throw new \InvalidArgumentException('A note explaining why this was skipped is required.');
        }

        $this->instances->markSkipped($instanceId, $callerId, $notes);

        return $this->attachAssignees([$this->instances->findByIdWithTaskInfo($instanceId)])[0];
    }

    private function requireMemberForInstance(int $callerId, int $instanceId): array
    {
        $instance = $this->instances->findById($instanceId);
        if ($instance === null) {
            throw new TaskNotFoundException('Task not found.');
        }

        $task = $this->tasks->findById((int) $instance['task_id']);
        $this->requireMember((int) $task['household_id'], $callerId);

        return $instance;
    }

    /**
     * attachAssignees(...) - bulk-attaches each row's task's assignee list
     * (as an `assignees` key: [{id, username}, ...]) in one query rather
     * than one per row, since listTasks()/listMyTasks() can return rows
     * spanning many different tasks.
     */
    private function attachAssignees(array $rows): array
    {
        $taskIds = array_values(array_unique(array_map(fn (array $row): int => (int) $row['task_id'], $rows)));
        $assigneesByTask = [];
        foreach ($this->tasks->listAssigneesForTasks($taskIds) as $assignee) {
            $assigneesByTask[(int) $assignee['task_id']][] = ['id' => (int) $assignee['id'], 'username' => $assignee['username']];
        }

        return array_map(function (array $row) use ($assigneesByTask): array {
            $row['assignees'] = $assigneesByTask[(int) $row['task_id']] ?? [];

            return $row;
        }, $rows);
    }

    /**
     * validateAssignees(...) - every id must be a member of the household;
     * 'everyone' mode additionally requires at least one, since a task
     * nobody's assigned to has nothing to generate per-assignee copies of.
     * 0/1 assignees with 'everyone' mode is allowed (behaviorally identical
     * to 'anyone' in that case) rather than specially rejected -- keeps the
     * validation rule simple, and the UI can still just default to 'anyone'.
     *
     * @param array<int> $assignedToUserIds
     *
     * @return array<int>
     */
    private function validateAssignees(int $householdId, array $assignedToUserIds, string $assignmentMode): array
    {
        $assignedToUserIds = array_values(array_unique(array_map('intval', $assignedToUserIds)));

        foreach ($assignedToUserIds as $userId) {
            if ($this->members->find($householdId, $userId) === null) {
                throw new \InvalidArgumentException('Assignees must be members of this household.');
            }
        }

        if ($assignmentMode === 'everyone' && $assignedToUserIds === []) {
            throw new \InvalidArgumentException("'everyone' assignment mode requires at least one assignee.");
        }

        return $assignedToUserIds;
    }

    private function validateAssignmentMode(?string $assignmentMode): string
    {
        $assignmentMode = $assignmentMode !== null && $assignmentMode !== '' ? $assignmentMode : 'anyone';
        if (!in_array($assignmentMode, self::ASSIGNMENT_MODES, true)) {
            throw new \InvalidArgumentException('assignment_mode must be one of: ' . implode(', ', self::ASSIGNMENT_MODES) . '.');
        }

        return $assignmentMode;
    }

    private function validateTitleAndDescription(string $title, ?string $description): array
    {
        $title = trim($title);
        if ($title === '' || strlen($title) > 150) {
            throw new \InvalidArgumentException('Task title must be 1-150 characters.');
        }

        $description = $description !== null ? trim($description) : null;
        $description = $description === '' ? null : $description;
        if ($description !== null && strlen($description) > self::MAX_NOTES_LENGTH) {
            throw new \InvalidArgumentException('Task description must be ' . self::MAX_NOTES_LENGTH . ' characters or fewer.');
        }

        return [$title, $description];
    }

    /**
     * validateNotes(...) - shared by createTask()/updateTask()/
     * completeInstance()/skipInstance(): trim, blank becomes null, and a
     * length cap -- same shape as validateTitleAndDescription()'s own
     * description handling. Doesn't enforce non-blank itself -- callers
     * that need that (skipInstance()) check the null result themselves.
     */
    private function validateNotes(?string $notes): ?string
    {
        $notes = $notes !== null ? trim($notes) : null;
        $notes = $notes === '' ? null : $notes;
        if ($notes !== null && strlen($notes) > self::MAX_NOTES_LENGTH) {
            throw new \InvalidArgumentException('Notes must be ' . self::MAX_NOTES_LENGTH . ' characters or fewer.');
        }

        return $notes;
    }

    /**
     * validateDueAt(...) - a recurring task always needs a real anchor date
     * (defaults to today if omitted, same as before the open-ended-task
     * follow-up); a one-off task left blank is now genuinely open-ended --
     * returns null rather than defaulting to today, so it's *never* due
     * (see the class's own "Open-ended tasks" docblock section) instead of
     * silently becoming "due today".
     */
    private function validateDueAt(?string $dueAt, bool $isRecurring): ?string
    {
        $dueAt = $dueAt !== null ? trim($dueAt) : '';
        if ($dueAt === '') {
            return $isRecurring ? (new \DateTimeImmutable('today'))->format('Y-m-d') : null;
        }

        $date = \DateTime::createFromFormat('Y-m-d', $dueAt);
        if ($date === false || $date->format('Y-m-d') !== $dueAt) {
            throw new \InvalidArgumentException('due_at must be in YYYY-MM-DD format.');
        }

        return $dueAt;
    }

    /**
     * validatePriority(...) - defaults to 'medium' only for a genuinely
     * open-ended task (one-off, no due date) that didn't specify one, so
     * every task that actually needs a priority for sorting purposes has
     * one; a dated or recurring task's priority, if any, is left exactly as
     * given (including null) since it isn't used to reorder anything.
     */
    private function validatePriority(?string $priority, bool $isOpenEnded): ?string
    {
        $priority = $priority !== null && $priority !== '' ? $priority : null;

        if ($priority !== null && !in_array($priority, self::PRIORITIES, true)) {
            throw new \InvalidArgumentException('priority must be one of: ' . implode(', ', self::PRIORITIES) . '.');
        }

        return $priority ?? ($isOpenEnded ? 'medium' : null);
    }

    /**
     * validateRecurrence(...) - recurrence_frequency and recurrence_interval
     * travel together: both null for a one-off task, both set for a
     * recurring one.
     */
    private function validateRecurrence(?string $recurrenceFrequency, ?int $recurrenceInterval): array
    {
        $recurrenceFrequency = $recurrenceFrequency !== null && $recurrenceFrequency !== '' ? $recurrenceFrequency : null;

        if ($recurrenceFrequency === null) {
            if ($recurrenceInterval !== null) {
                throw new \InvalidArgumentException('recurrence_interval requires a recurrence_frequency.');
            }

            return [null, null];
        }

        if (!in_array($recurrenceFrequency, self::RECURRENCE_FREQUENCIES, true)) {
            throw new \InvalidArgumentException('recurrence_frequency must be one of: ' . implode(', ', self::RECURRENCE_FREQUENCIES) . '.');
        }

        $recurrenceInterval ??= 1;
        if ($recurrenceInterval < 1 || $recurrenceInterval > self::MAX_RECURRENCE_INTERVAL) {
            throw new \InvalidArgumentException('recurrence_interval must be between 1 and ' . self::MAX_RECURRENCE_INTERVAL . '.');
        }

        return [$recurrenceFrequency, $recurrenceInterval];
    }

    private function requireMember(int $householdId, int $userId): void
    {
        if ($this->members->find($householdId, $userId) === null) {
            throw new NotAHouseholdMemberException('You are not a member of this household.');
        }
    }
}

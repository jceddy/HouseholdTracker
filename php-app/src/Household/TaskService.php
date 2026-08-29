<?php

declare(strict_types=1);

namespace HouseholdTracker\Household;

use HouseholdTracker\Repository\HouseholdMemberRepository;
use HouseholdTracker\Repository\HouseholdTaskInstanceRepository;
use HouseholdTracker\Repository\HouseholdTaskRepository;

/**
 * Household task/chore tracking (issue #12): one-off tasks and recurring
 * chores (daily/weekly/monthly/annual, on an N-interval), assignable to any
 * number of household members. Tasks are a shared household resource, not
 * per-user content -- like pets (issue #7), any member may create/edit/
 * delete/complete any task, regardless of who created or is assigned it.
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
 *     occurrence (see createTask()/HouseholdTaskInstanceRepository's own
 *     docblock) and must complete their own copy; the others are unaffected.
 * A task's assignee list is edited as a whole (replaceAssignees()), the
 * same way its other fields are -- there's no separate add/remove-one-
 * assignee endpoint.
 */
final class TaskService
{
    private const RECURRENCE_FREQUENCIES = ['daily', 'weekly', 'monthly', 'annual'];
    private const ASSIGNMENT_MODES = ['anyone', 'everyone'];
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
        ?string $dueAt
    ): array {
        $this->requireMember($householdId, $callerId);
        [$title, $description] = $this->validateTitleAndDescription($title, $description);
        $assignmentMode = $this->validateAssignmentMode($assignmentMode);
        $assignedToUserIds = $this->validateAssignees($householdId, $assignedToUserIds, $assignmentMode);
        [$recurrenceFrequency, $recurrenceInterval] = $this->validateRecurrence($recurrenceFrequency, $recurrenceInterval);
        $dueAt = $this->validateDueAt($dueAt);

        $task = $this->tasks->create($householdId, $callerId, $title, $description, $assignmentMode, $recurrenceFrequency, $recurrenceInterval, $dueAt);
        $this->tasks->replaceAssignees((int) $task['id'], $assignedToUserIds);

        $instances = $assignmentMode === 'everyone'
            ? array_map(fn (int $userId): array => $this->instances->create((int) $task['id'], $dueAt, $userId), $assignedToUserIds)
            : [$this->instances->create((int) $task['id'], $dueAt)];

        return $this->attachAssignees(array_map(
            fn (array $instance): array => $this->instances->findByIdWithTaskInfo((int) $instance['id']),
            $instances
        ));
    }

    /**
     * updateTask(...) - edits the parent definition's title/description/
     * assignees/mode/recurrence, *and* moves this specific instance's own
     * due date. Doesn't touch the definition's start_date (see
     * HouseholdTaskRepository::update()'s own docblock) or any other
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
        ?string $dueAt
    ): array {
        $instance = $this->requireMemberForInstance($callerId, $instanceId);
        $task = $this->tasks->findById((int) $instance['task_id']);
        [$title, $description] = $this->validateTitleAndDescription($title, $description);
        $assignmentMode = $this->validateAssignmentMode($assignmentMode);
        $assignedToUserIds = $this->validateAssignees((int) $task['household_id'], $assignedToUserIds, $assignmentMode);
        [$recurrenceFrequency, $recurrenceInterval] = $this->validateRecurrence($recurrenceFrequency, $recurrenceInterval);
        $dueAt = $this->validateDueAt($dueAt);

        $this->tasks->update((int) $task['id'], $title, $description, $assignmentMode, $recurrenceFrequency, $recurrenceInterval);
        $this->tasks->replaceAssignees((int) $task['id'], $assignedToUserIds);
        $this->instances->updateDueAt($instanceId, $dueAt);

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

    public function completeInstance(int $callerId, int $instanceId, ?string $notes): array
    {
        $instance = $this->requireMemberForInstance($callerId, $instanceId);

        $notes = $notes !== null ? trim($notes) : null;
        $notes = $notes === '' ? null : $notes;
        if ($notes !== null && strlen($notes) > self::MAX_NOTES_LENGTH) {
            throw new \InvalidArgumentException('Completion notes must be ' . self::MAX_NOTES_LENGTH . ' characters or fewer.');
        }

        $this->instances->markDone($instanceId, $callerId, $notes);

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
     * validateDueAt(...) - defaults to today when omitted, for a one-off
     * task or a recurring one alike (a recurring task's due_at here is
     * still just the *anchor* -- its literal due date is required now that
     * every task, recurring or not, always has at least one concrete
     * instance).
     */
    private function validateDueAt(?string $dueAt): string
    {
        $dueAt = $dueAt !== null ? trim($dueAt) : '';
        if ($dueAt === '') {
            return (new \DateTimeImmutable('today'))->format('Y-m-d');
        }

        $date = \DateTime::createFromFormat('Y-m-d', $dueAt);
        if ($date === false || $date->format('Y-m-d') !== $dueAt) {
            throw new \InvalidArgumentException('due_at must be in YYYY-MM-DD format.');
        }

        return $dueAt;
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

<?php

declare(strict_types=1);

namespace HouseholdTracker\Household;

use HouseholdTracker\Repository\HouseholdMemberRepository;
use HouseholdTracker\Repository\HouseholdTaskInstanceRepository;
use HouseholdTracker\Repository\HouseholdTaskRepository;

/**
 * Household task/chore tracking (issue #12): one-off tasks and recurring
 * chores (daily/weekly/monthly/annual, on an N-interval), assignable to any
 * household member. Tasks are a shared household resource, not per-user
 * content -- like pets (issue #7), any member may create/edit/delete/
 * complete any task, not just its creator or assignee.
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
 */
final class TaskService
{
    private const RECURRENCE_FREQUENCIES = ['daily', 'weekly', 'monthly', 'annual'];
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

        return $this->instances->listForHousehold($householdId);
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
        return $this->instances->listAssignedToUser($userId);
    }

    public function createTask(
        int $callerId,
        int $householdId,
        string $title,
        ?string $description,
        ?int $assignedToUserId,
        ?string $recurrenceFrequency,
        ?int $recurrenceInterval,
        ?string $dueAt
    ): array {
        $this->requireMember($householdId, $callerId);
        [$title, $description] = $this->validateTitleAndDescription($title, $description);
        $this->requireMemberIfAssigned($householdId, $assignedToUserId);
        [$recurrenceFrequency, $recurrenceInterval] = $this->validateRecurrence($recurrenceFrequency, $recurrenceInterval);
        $dueAt = $this->validateDueAt($dueAt);

        $task = $this->tasks->create($householdId, $callerId, $title, $description, $assignedToUserId, $recurrenceFrequency, $recurrenceInterval, $dueAt);
        $instance = $this->instances->create((int) $task['id'], $dueAt);

        return $this->instances->findByIdWithTaskInfo((int) $instance['id']);
    }

    /**
     * updateTask(...) - edits the parent definition's title/description/
     * assignee/recurrence, *and* moves this specific instance's own due
     * date. Doesn't touch the definition's start_date (see
     * HouseholdTaskRepository::update()'s own docblock) or any other
     * instance -- a recurring task's already-generated future occurrences
     * keep whatever dates cron gave them.
     */
    public function updateTask(
        int $callerId,
        int $instanceId,
        string $title,
        ?string $description,
        ?int $assignedToUserId,
        ?string $recurrenceFrequency,
        ?int $recurrenceInterval,
        ?string $dueAt
    ): array {
        $instance = $this->requireMemberForInstance($callerId, $instanceId);
        $task = $this->tasks->findById((int) $instance['task_id']);
        [$title, $description] = $this->validateTitleAndDescription($title, $description);
        $this->requireMemberIfAssigned((int) $task['household_id'], $assignedToUserId);
        [$recurrenceFrequency, $recurrenceInterval] = $this->validateRecurrence($recurrenceFrequency, $recurrenceInterval);
        $dueAt = $this->validateDueAt($dueAt);

        $this->tasks->update((int) $task['id'], $title, $description, $assignedToUserId, $recurrenceFrequency, $recurrenceInterval);
        $this->instances->updateDueAt($instanceId, $dueAt);

        return $this->instances->findByIdWithTaskInfo($instanceId);
    }

    /**
     * deleteInstance(...) - a one-off task has exactly one instance ever,
     * so deleting it deletes the whole definition (which cascades back to
     * the instance itself) rather than leaving an orphaned definition
     * behind. A recurring task's instance is just one occurrence among
     * others (existing and future), so only that row goes -- "skip this
     * occurrence" -- leaving the definition and its other instances alone.
     */
    public function deleteInstance(int $callerId, int $instanceId): void
    {
        $instance = $this->requireMemberForInstance($callerId, $instanceId);
        $task = $this->tasks->findById((int) $instance['task_id']);

        if ($task['recurrence_frequency'] === null) {
            $this->tasks->delete((int) $task['id']);

            return;
        }

        $this->instances->delete($instanceId);
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

        return $this->instances->findByIdWithTaskInfo($instanceId);
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

    private function requireMemberIfAssigned(int $householdId, ?int $assignedToUserId): void
    {
        if ($assignedToUserId !== null && $this->members->find($householdId, $assignedToUserId) === null) {
            throw new \InvalidArgumentException('Assignee must be a member of this household.');
        }
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

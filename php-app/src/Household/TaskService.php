<?php

declare(strict_types=1);

namespace HouseholdTracker\Household;

use HouseholdTracker\Repository\HouseholdMemberRepository;
use HouseholdTracker\Repository\HouseholdTaskCompletionRepository;
use HouseholdTracker\Repository\HouseholdTaskRepository;

/**
 * Household task/chore tracking (issue #12): one-off tasks and recurring
 * chores (daily/weekly/monthly/annual, on an N-interval), assignable to any
 * household member. Tasks are a shared household resource, not per-user
 * content -- like pets (issue #7), any member may create/edit/delete/
 * complete any task, not just its creator or assignee.
 *
 * A task's status only ever reaches 'done' through completeTask() (which
 * also logs completion history) -- updateTask() deliberately rejects 'done'
 * so a completed task is never left without a matching
 * household_task_completions row.
 */
final class TaskService
{
    private const RECURRENCE_FREQUENCIES = ['daily', 'weekly', 'monthly', 'annual'];
    private const MAX_RECURRENCE_INTERVAL = 1000;
    private const MAX_NOTES_LENGTH = 2000;

    public function __construct(
        private readonly HouseholdMemberRepository $members,
        private readonly HouseholdTaskRepository $tasks,
        private readonly HouseholdTaskCompletionRepository $completions,
    ) {
    }

    public function listTasks(int $callerId, int $householdId): array
    {
        $this->requireMember($householdId, $callerId);

        return $this->tasks->listForHousehold($householdId);
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
        [$recurrenceFrequency, $recurrenceInterval, $dueAt] = $this->validateRecurrence($recurrenceFrequency, $recurrenceInterval, $dueAt);

        return $this->tasks->create(
            $householdId,
            $callerId,
            $title,
            $description,
            $assignedToUserId,
            $recurrenceFrequency,
            $recurrenceInterval,
            $dueAt
        );
    }

    public function updateTask(
        int $callerId,
        int $taskId,
        string $title,
        ?string $description,
        ?int $assignedToUserId,
        string $status,
        ?string $recurrenceFrequency,
        ?int $recurrenceInterval,
        ?string $dueAt
    ): array {
        $task = $this->requireMemberForTask($callerId, $taskId);
        [$title, $description] = $this->validateTitleAndDescription($title, $description);
        $this->requireMemberIfAssigned((int) $task['household_id'], $assignedToUserId);
        [$recurrenceFrequency, $recurrenceInterval, $dueAt] = $this->validateRecurrence($recurrenceFrequency, $recurrenceInterval, $dueAt);

        if (!in_array($status, ['open', 'in_progress'], true)) {
            throw new \InvalidArgumentException('status must be "open" or "in_progress" -- use complete to mark a task done.');
        }

        $taskId = (int) $task['id'];
        $this->tasks->update($taskId, $title, $description, $assignedToUserId, $status, $recurrenceFrequency, $recurrenceInterval, $dueAt);

        return $this->tasks->findById($taskId);
    }

    public function deleteTask(int $callerId, int $taskId): void
    {
        $task = $this->requireMemberForTask($callerId, $taskId);
        $this->tasks->delete((int) $task['id']);
    }

    /**
     * completeTask(...) - logs completion history and, for a recurring
     * chore, advances next_due_at by exactly one interval from its
     * *previous scheduled* due date (RecurrenceCalculator::advance()) --
     * never from whenever it actually got done, so the schedule stays
     * anchored (e.g. trash day stays Monday) instead of drifting later and
     * later after an occasional late completion. This was an explicit open
     * question in issue #12; resolved this way rather than rescheduling
     * from the completion date. A one-off task is simply marked 'done'.
     * See RecurrenceCalculator's own docblock for a related, deliberate
     * consequence: a month-end monthly/annual task that clamps down (e.g.
     * Jan 31 -> Feb 28) does not spring back to its original day-of-month
     * once a longer month comes around.
     */
    public function completeTask(int $callerId, int $taskId, ?string $notes): array
    {
        $task = $this->requireMemberForTask($callerId, $taskId);

        $notes = $notes !== null ? trim($notes) : null;
        $notes = $notes === '' ? null : $notes;
        if ($notes !== null && strlen($notes) > self::MAX_NOTES_LENGTH) {
            throw new \InvalidArgumentException('Completion notes must be ' . self::MAX_NOTES_LENGTH . ' characters or fewer.');
        }

        $taskId = (int) $task['id'];
        $this->completions->create($taskId, $callerId, $notes);

        $assignedToUserId = $task['assigned_to_user_id'] !== null ? (int) $task['assigned_to_user_id'] : null;

        if ($task['recurrence_frequency'] === null) {
            $this->tasks->update($taskId, (string) $task['title'], $task['description'], $assignedToUserId, 'done', null, null, $task['next_due_at']);

            return $this->tasks->findById($taskId);
        }

        $nextDueAt = RecurrenceCalculator::advance(
            new \DateTimeImmutable((string) $task['next_due_at']),
            (string) $task['recurrence_frequency'],
            (int) $task['recurrence_interval']
        );

        $this->tasks->update(
            $taskId,
            (string) $task['title'],
            $task['description'],
            $assignedToUserId,
            'open',
            (string) $task['recurrence_frequency'],
            (int) $task['recurrence_interval'],
            $nextDueAt->format('Y-m-d')
        );

        return $this->tasks->findById($taskId);
    }

    private function requireMemberForTask(int $callerId, int $taskId): array
    {
        $task = $this->tasks->findById($taskId);
        if ($task === null) {
            throw new TaskNotFoundException('Task not found.');
        }

        $this->requireMember((int) $task['household_id'], $callerId);

        return $task;
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
     * validateRecurrence(...) - recurrence_frequency and recurrence_interval
     * travel together (both null for a one-off task, both set for a
     * recurring one); due_at is required for a recurring task (it's the
     * anchor RecurrenceCalculator advances from) but optional for a one-off
     * task (a plain deadline, or no deadline at all).
     */
    private function validateRecurrence(?string $recurrenceFrequency, ?int $recurrenceInterval, ?string $dueAt): array
    {
        $dueAt = $dueAt !== null ? trim($dueAt) : null;
        $dueAt = $dueAt === '' ? null : $dueAt;
        if ($dueAt !== null) {
            $date = \DateTime::createFromFormat('Y-m-d', $dueAt);
            if ($date === false || $date->format('Y-m-d') !== $dueAt) {
                throw new \InvalidArgumentException('due_at must be in YYYY-MM-DD format.');
            }
        }

        $recurrenceFrequency = $recurrenceFrequency !== null && $recurrenceFrequency !== '' ? $recurrenceFrequency : null;

        if ($recurrenceFrequency === null) {
            if ($recurrenceInterval !== null) {
                throw new \InvalidArgumentException('recurrence_interval requires a recurrence_frequency.');
            }

            return [null, null, $dueAt];
        }

        if (!in_array($recurrenceFrequency, self::RECURRENCE_FREQUENCIES, true)) {
            throw new \InvalidArgumentException('recurrence_frequency must be one of: ' . implode(', ', self::RECURRENCE_FREQUENCIES) . '.');
        }

        $recurrenceInterval ??= 1;
        if ($recurrenceInterval < 1 || $recurrenceInterval > self::MAX_RECURRENCE_INTERVAL) {
            throw new \InvalidArgumentException('recurrence_interval must be between 1 and ' . self::MAX_RECURRENCE_INTERVAL . '.');
        }

        if ($dueAt === null) {
            throw new \InvalidArgumentException('A recurring task needs a due_at to anchor its schedule.');
        }

        return [$recurrenceFrequency, $recurrenceInterval, $dueAt];
    }

    private function requireMember(int $householdId, int $userId): void
    {
        if ($this->members->find($householdId, $userId) === null) {
            throw new NotAHouseholdMemberException('You are not a member of this household.');
        }
    }
}

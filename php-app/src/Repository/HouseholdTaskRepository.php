<?php

declare(strict_types=1);

namespace HouseholdTracker\Repository;

use HouseholdTracker\Database\Connection;

/**
 * A task/chore *definition* -- a recurring rule, or a one-off -- issue #12,
 * reworked into definition+instances by its own follow-up (see
 * database/migrations/0009_add_household_task_instances.sql). Due
 * dates/status/completion live on HouseholdTaskInstanceRepository instead.
 * Assignees (issue #12's own multi-assignee follow-up, migration `0010`)
 * live in household_task_assignees, a many-to-many table, rather than a
 * single column here -- see this class's own assignee methods below, and
 * TaskService's docblock for what `assignment_mode` does with them.
 */
final class HouseholdTaskRepository
{
    public function create(
        int $householdId,
        int $createdByUserId,
        string $title,
        ?string $description,
        string $assignmentMode,
        ?string $recurrenceFrequency,
        ?int $recurrenceInterval,
        string $startDate
    ): array {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'INSERT INTO household_tasks
                (household_id, title, description, assignment_mode, recurrence_frequency, recurrence_interval, start_date, created_by_user_id)
             VALUES
                (:household_id, :title, :description, :assignment_mode, :recurrence_frequency, :recurrence_interval, :start_date, :created_by_user_id)'
        );
        $stmt->execute([
            'household_id' => $householdId,
            'title' => $title,
            'description' => $description,
            'assignment_mode' => $assignmentMode,
            'recurrence_frequency' => $recurrenceFrequency,
            'recurrence_interval' => $recurrenceInterval,
            'start_date' => $startDate,
            'created_by_user_id' => $createdByUserId,
        ]);

        return $this->findById((int) $pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM household_tasks WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * update(...) - the definition's own fields only. Deliberately doesn't
     * touch start_date -- once a task has instances, start_date is just
     * historical (cron generates each new instance from the *latest
     * existing* instance's due date, not from this column); editing a
     * task's due date moves the specific instance being edited instead, via
     * HouseholdTaskInstanceRepository::updateDueAt() (see TaskService::
     * updateTask()). Also doesn't touch household_task_assignees -- see
     * replaceAssignees() below, called separately.
     */
    public function update(
        int $id,
        string $title,
        ?string $description,
        string $assignmentMode,
        ?string $recurrenceFrequency,
        ?int $recurrenceInterval
    ): void {
        $stmt = Connection::get()->prepare(
            'UPDATE household_tasks
             SET title = :title, description = :description, assignment_mode = :assignment_mode,
                 recurrence_frequency = :recurrence_frequency, recurrence_interval = :recurrence_interval
             WHERE id = :id'
        );
        $stmt->execute([
            'title' => $title,
            'description' => $description,
            'assignment_mode' => $assignmentMode,
            'recurrence_frequency' => $recurrenceFrequency,
            'recurrence_interval' => $recurrenceInterval,
            'id' => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = Connection::get()->prepare('DELETE FROM household_tasks WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * replaceAssignees(...) - wholesale replace, not a diff; simplest
     * correct thing for a small list edited as a whole via a checkbox set
     * in the UI, same as how updating a task's other fields already works.
     */
    public function replaceAssignees(int $taskId, array $userIds): void
    {
        $pdo = Connection::get();
        $pdo->prepare('DELETE FROM household_task_assignees WHERE task_id = :task_id')->execute(['task_id' => $taskId]);

        if ($userIds === []) {
            return;
        }

        $stmt = $pdo->prepare('INSERT INTO household_task_assignees (task_id, user_id) VALUES (:task_id, :user_id)');
        foreach (array_unique($userIds) as $userId) {
            $stmt->execute(['task_id' => $taskId, 'user_id' => $userId]);
        }
    }

    public function listAssigneeIds(int $taskId): array
    {
        $stmt = Connection::get()->prepare('SELECT user_id FROM household_task_assignees WHERE task_id = :task_id');
        $stmt->execute(['task_id' => $taskId]);

        return array_map('intval', array_column($stmt->fetchAll(), 'user_id'));
    }

    /**
     * listAssigneesForTasks(...) - bulk-fetches {task_id, id, username} for
     * every assignee of every given task id in one query, so building a
     * task list's response doesn't run one assignee query per row (see
     * TaskService's own list methods, which group these by task_id in PHP
     * and attach the result to each instance row).
     *
     * @param array<int> $taskIds
     */
    public function listAssigneesForTasks(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
        $stmt = Connection::get()->prepare(
            "SELECT household_task_assignees.task_id, users.id, users.username
             FROM household_task_assignees
             INNER JOIN users ON users.id = household_task_assignees.user_id
             WHERE household_task_assignees.task_id IN ({$placeholders})
             ORDER BY users.username ASC"
        );
        $stmt->execute(array_values($taskIds));

        return $stmt->fetchAll();
    }

    /**
     * listAllRecurring(...) - every recurring definition, across every
     * household -- cron-only (bin/generate_task_instances.php), not exposed
     * over HTTP.
     */
    public function listAllRecurring(): array
    {
        $stmt = Connection::get()->query(
            'SELECT * FROM household_tasks WHERE recurrence_frequency IS NOT NULL'
        );

        return $stmt->fetchAll();
    }

    /**
     * deleteOrphanedOneOffTasks(...) - a one-off definition normally gets
     * deleted together with its instance(s) the moment none are left
     * (TaskService::deleteInstance()), but a very old, never-completed
     * one-off task's instance can also disappear via
     * purgeExpiredPendingOlderThan(), leaving the definition behind with
     * nothing pointing at it. Cron-only backstop for that; a recurring
     * definition is never auto-deleted here regardless of its current
     * instance count. Returns the number removed.
     */
    public function deleteOrphanedOneOffTasks(): int
    {
        return Connection::get()->exec(
            'DELETE FROM household_tasks
             WHERE recurrence_frequency IS NULL
               AND id NOT IN (SELECT DISTINCT task_id FROM household_task_instances)'
        );
    }
}

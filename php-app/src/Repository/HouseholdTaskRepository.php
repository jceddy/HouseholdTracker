<?php

declare(strict_types=1);

namespace HouseholdTracker\Repository;

use HouseholdTracker\Database\Connection;

/**
 * A task/chore *definition* -- a recurring rule, or a one-off -- issue #12,
 * reworked into definition+instances by its own follow-up (see
 * database/migrations/0009_add_household_task_instances.sql). Due
 * dates/status/completion live on HouseholdTaskInstanceRepository instead.
 */
final class HouseholdTaskRepository
{
    public function create(
        int $householdId,
        int $createdByUserId,
        string $title,
        ?string $description,
        ?int $assignedToUserId,
        ?string $recurrenceFrequency,
        ?int $recurrenceInterval,
        string $startDate
    ): array {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'INSERT INTO household_tasks
                (household_id, title, description, assigned_to_user_id, recurrence_frequency, recurrence_interval, start_date, created_by_user_id)
             VALUES
                (:household_id, :title, :description, :assigned_to_user_id, :recurrence_frequency, :recurrence_interval, :start_date, :created_by_user_id)'
        );
        $stmt->execute([
            'household_id' => $householdId,
            'title' => $title,
            'description' => $description,
            'assigned_to_user_id' => $assignedToUserId,
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
     * updateTask()).
     */
    public function update(
        int $id,
        string $title,
        ?string $description,
        ?int $assignedToUserId,
        ?string $recurrenceFrequency,
        ?int $recurrenceInterval
    ): void {
        $stmt = Connection::get()->prepare(
            'UPDATE household_tasks
             SET title = :title, description = :description, assigned_to_user_id = :assigned_to_user_id,
                 recurrence_frequency = :recurrence_frequency, recurrence_interval = :recurrence_interval
             WHERE id = :id'
        );
        $stmt->execute([
            'title' => $title,
            'description' => $description,
            'assigned_to_user_id' => $assignedToUserId,
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
     * deleted together with its single instance (TaskService::
     * deleteInstance()), but a very old, never-completed one-off task's
     * instance can also disappear via purgeExpiredPendingOlderThan(),
     * leaving the definition behind with nothing pointing at it. Cron-only
     * backstop for that; a recurring definition is never auto-deleted here
     * regardless of its current instance count. Returns the number removed.
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

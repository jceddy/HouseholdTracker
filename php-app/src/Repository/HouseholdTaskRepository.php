<?php

declare(strict_types=1);

namespace HouseholdTracker\Repository;

use HouseholdTracker\Database\Connection;

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
        ?string $nextDueAt
    ): array {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'INSERT INTO household_tasks
                (household_id, title, description, assigned_to_user_id, recurrence_frequency, recurrence_interval, next_due_at, created_by_user_id)
             VALUES
                (:household_id, :title, :description, :assigned_to_user_id, :recurrence_frequency, :recurrence_interval, :next_due_at, :created_by_user_id)'
        );
        $stmt->execute([
            'household_id' => $householdId,
            'title' => $title,
            'description' => $description,
            'assigned_to_user_id' => $assignedToUserId,
            'recurrence_frequency' => $recurrenceFrequency,
            'recurrence_interval' => $recurrenceInterval,
            'next_due_at' => $nextDueAt,
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
     * listForHousehold(...) - joins the assignee's username (nullable) and a
     * summary of completion history (count + most recent date) rather than
     * every individual completion row, to keep the list view lightweight.
     * Ordered so tasks with a due date come first (soonest first), then
     * undated one-off tasks last, newest first.
     */
    public function listForHousehold(int $householdId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT household_tasks.*, users.username AS assigned_to_username,
                    COUNT(household_task_completions.id) AS completion_count,
                    MAX(household_task_completions.completed_at) AS last_completed_at
             FROM household_tasks
             LEFT JOIN users ON users.id = household_tasks.assigned_to_user_id
             LEFT JOIN household_task_completions ON household_task_completions.task_id = household_tasks.id
             WHERE household_tasks.household_id = :household_id
             GROUP BY household_tasks.id
             ORDER BY (household_tasks.next_due_at IS NULL), household_tasks.next_due_at ASC, household_tasks.created_at DESC'
        );
        $stmt->execute(['household_id' => $householdId]);

        return $stmt->fetchAll();
    }

    /**
     * listAssignedToUser(...) - every open/in_progress task assigned to this
     * user across *every* household they belong to (the "My Tasks" view),
     * not just one. The INNER JOIN back to household_members (matching both
     * the household and the assignee) guards against a stale assignment
     * surviving after the assignee has since left that household -- removing
     * a member doesn't currently clear assigned_to_user_id on their tasks,
     * so without this join a former member would keep seeing that
     * household's tasks here forever. Same due-date-ascending,
     * undated-last ordering as listForHousehold().
     */
    public function listAssignedToUser(int $userId): array
    {
        $stmt = Connection::get()->prepare(
            "SELECT household_tasks.*, households.name AS household_name,
                    COUNT(household_task_completions.id) AS completion_count,
                    MAX(household_task_completions.completed_at) AS last_completed_at
             FROM household_tasks
             INNER JOIN households ON households.id = household_tasks.household_id
             INNER JOIN household_members
                 ON household_members.household_id = household_tasks.household_id
                AND household_members.user_id = household_tasks.assigned_to_user_id
             LEFT JOIN household_task_completions ON household_task_completions.task_id = household_tasks.id
             WHERE household_tasks.assigned_to_user_id = :user_id AND household_tasks.status != 'done'
             GROUP BY household_tasks.id
             ORDER BY (household_tasks.next_due_at IS NULL), household_tasks.next_due_at ASC, household_tasks.created_at DESC"
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public function update(
        int $id,
        string $title,
        ?string $description,
        ?int $assignedToUserId,
        string $status,
        ?string $recurrenceFrequency,
        ?int $recurrenceInterval,
        ?string $nextDueAt
    ): void {
        $stmt = Connection::get()->prepare(
            'UPDATE household_tasks
             SET title = :title, description = :description, assigned_to_user_id = :assigned_to_user_id,
                 status = :status, recurrence_frequency = :recurrence_frequency,
                 recurrence_interval = :recurrence_interval, next_due_at = :next_due_at
             WHERE id = :id'
        );
        $stmt->execute([
            'title' => $title,
            'description' => $description,
            'assigned_to_user_id' => $assignedToUserId,
            'status' => $status,
            'recurrence_frequency' => $recurrenceFrequency,
            'recurrence_interval' => $recurrenceInterval,
            'next_due_at' => $nextDueAt,
            'id' => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = Connection::get()->prepare('DELETE FROM household_tasks WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}

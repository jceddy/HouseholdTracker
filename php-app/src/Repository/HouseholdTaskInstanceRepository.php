<?php

declare(strict_types=1);

namespace HouseholdTracker\Repository;

use HouseholdTracker\Database\Connection;

/**
 * One row per concrete task occurrence (issue #12 follow-up) -- see
 * household_task_instances' own migration comment
 * (database/migrations/0009_add_household_task_instances.sql) for why this
 * exists as its own table rather than a single mutable pointer on
 * household_tasks.
 */
final class HouseholdTaskInstanceRepository
{
    public function create(int $taskId, string $dueAt): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('INSERT INTO household_task_instances (task_id, due_at) VALUES (:task_id, :due_at)');
        $stmt->execute(['task_id' => $taskId, 'due_at' => $dueAt]);

        return $this->findById((int) $pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM household_task_instances WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * findByIdWithTaskInfo(...) - the single-row shape returned from
     * create/update/complete -- the instance's own fields plus its parent
     * definition's, the way listForHousehold()/listAssignedToUser() already
     * return each row (see their own docblocks).
     */
    public function findByIdWithTaskInfo(int $id): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT household_task_instances.*, household_tasks.household_id, household_tasks.title,
                    household_tasks.description, household_tasks.assigned_to_user_id,
                    household_tasks.recurrence_frequency, household_tasks.recurrence_interval,
                    users.username AS assigned_to_username
             FROM household_task_instances
             INNER JOIN household_tasks ON household_tasks.id = household_task_instances.task_id
             LEFT JOIN users ON users.id = household_tasks.assigned_to_user_id
             WHERE household_task_instances.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findLatestForTask(int $taskId): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM household_task_instances WHERE task_id = :task_id ORDER BY due_at DESC LIMIT 1'
        );
        $stmt->execute(['task_id' => $taskId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function existsForTaskAndDate(int $taskId, string $dueAt): bool
    {
        $stmt = Connection::get()->prepare(
            'SELECT 1 FROM household_task_instances WHERE task_id = :task_id AND due_at = :due_at LIMIT 1'
        );
        $stmt->execute(['task_id' => $taskId, 'due_at' => $dueAt]);

        return $stmt->fetch() !== false;
    }

    public function updateDueAt(int $id, string $dueAt): void
    {
        $stmt = Connection::get()->prepare('UPDATE household_task_instances SET due_at = :due_at WHERE id = :id');
        $stmt->execute(['due_at' => $dueAt, 'id' => $id]);
    }

    public function markDone(int $id, int $completedByUserId, ?string $notes): void
    {
        $stmt = Connection::get()->prepare(
            "UPDATE household_task_instances
             SET status = 'done', completed_at = NOW(), completed_by_user_id = :completed_by_user_id, notes = :notes
             WHERE id = :id"
        );
        $stmt->execute(['completed_by_user_id' => $completedByUserId, 'notes' => $notes, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = Connection::get()->prepare('DELETE FROM household_task_instances WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * listForHousehold(...) - only *pending* instances (the actionable
     * backlog -- a fallen-behind recurring task shows as several
     * individually-completable rows, not one perpetually-overdue one),
     * each carrying its parent definition's fields plus a completion-history
     * summary (count + most recent date) computed from that task's *other*,
     * done instances -- via correlated subqueries rather than a join, since
     * the main result set is filtered to pending while the summary needs to
     * see done ones too.
     */
    public function listForHousehold(int $householdId): array
    {
        $stmt = Connection::get()->prepare(
            "SELECT household_task_instances.*, household_tasks.title, household_tasks.description,
                    household_tasks.assigned_to_user_id, household_tasks.recurrence_frequency,
                    household_tasks.recurrence_interval, users.username AS assigned_to_username,
                    (SELECT COUNT(*) FROM household_task_instances completed
                        WHERE completed.task_id = household_tasks.id AND completed.status = 'done') AS completion_count,
                    (SELECT MAX(completed.completed_at) FROM household_task_instances completed
                        WHERE completed.task_id = household_tasks.id AND completed.status = 'done') AS last_completed_at
             FROM household_task_instances
             INNER JOIN household_tasks ON household_tasks.id = household_task_instances.task_id
             LEFT JOIN users ON users.id = household_tasks.assigned_to_user_id
             WHERE household_tasks.household_id = :household_id AND household_task_instances.status = 'pending'
             ORDER BY household_task_instances.due_at ASC, household_task_instances.created_at DESC"
        );
        $stmt->execute(['household_id' => $householdId]);

        return $stmt->fetchAll();
    }

    /**
     * listAssignedToUser(...) - the "My Tasks" view's pending instances
     * across every household the user belongs to. Same household_members
     * join guard as before the instances split, for the same reason: a
     * stale assignment shouldn't keep surfacing here after the assignee has
     * since left that household.
     */
    public function listAssignedToUser(int $userId): array
    {
        $stmt = Connection::get()->prepare(
            "SELECT household_task_instances.*, household_tasks.household_id, households.name AS household_name,
                    household_tasks.title, household_tasks.description, household_tasks.recurrence_frequency,
                    household_tasks.recurrence_interval,
                    (SELECT COUNT(*) FROM household_task_instances completed
                        WHERE completed.task_id = household_tasks.id AND completed.status = 'done') AS completion_count,
                    (SELECT MAX(completed.completed_at) FROM household_task_instances completed
                        WHERE completed.task_id = household_tasks.id AND completed.status = 'done') AS last_completed_at
             FROM household_task_instances
             INNER JOIN household_tasks ON household_tasks.id = household_task_instances.task_id
             INNER JOIN households ON households.id = household_tasks.household_id
             INNER JOIN household_members
                 ON household_members.household_id = household_tasks.household_id
                AND household_members.user_id = household_tasks.assigned_to_user_id
             WHERE household_tasks.assigned_to_user_id = :user_id AND household_task_instances.status = 'pending'
             ORDER BY household_task_instances.due_at ASC, household_task_instances.created_at DESC"
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    /**
     * purgeDoneOlderThan(...)/purgeExpiredPendingOlderThan(...) - the daily
     * cron script's cleanup half (bin/generate_task_instances.php): old
     * completed instances are pure history past a point, and a pending
     * instance nobody ever completed shouldn't clutter the list forever
     * either. Return the number of rows removed, for the script's own log
     * output.
     */
    public function purgeDoneOlderThan(int $days): int
    {
        $stmt = Connection::get()->prepare(
            "DELETE FROM household_task_instances WHERE status = 'done' AND completed_at < (NOW() - INTERVAL :days DAY)"
        );
        $stmt->bindValue('days', $days, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    public function purgeExpiredPendingOlderThan(int $days): int
    {
        $stmt = Connection::get()->prepare(
            "DELETE FROM household_task_instances WHERE status = 'pending' AND due_at < (CURDATE() - INTERVAL :days DAY)"
        );
        $stmt->bindValue('days', $days, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }
}

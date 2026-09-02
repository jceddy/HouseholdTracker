<?php

declare(strict_types=1);

namespace HouseholdTracker\Repository;

use HouseholdTracker\Database\Connection;

/**
 * One row per concrete task occurrence (issue #12 follow-up) -- see
 * household_task_instances' own migration comment
 * (database/migrations/0009_add_household_task_instances.sql) for why this
 * exists as its own table rather than a single mutable pointer on
 * household_tasks. `assigned_to_user_id` here (migration `0010`) is only
 * set for an 'everyone'-mode task's own per-assignee copy of an occurrence
 * -- NULL for a shared ('anyone'-mode, or 0/1-assignee) instance. See
 * TaskService's docblock for the full anyone/everyone design.
 *
 * `due_at` (migration `0011`) is nullable: NULL means an open-ended
 * one-off task with no deadline. Only ever NULL for a one-off task's
 * instance -- a recurring task's occurrences always carry a real date, see
 * TaskService's docblock.
 */
final class HouseholdTaskInstanceRepository
{
    /**
     * FIELD() returns each value's 1-based position in the list (0 if
     * NULL/unmatched); ascending on that puts 'critical' first and 'low'
     * last -- "reverse-priority order (highest priority to lowest)".
     */
    private const PRIORITY_ORDER_SQL = "FIELD(household_tasks.priority, 'critical', 'high', 'medium', 'low')";

    public function create(int $taskId, ?string $dueAt, ?int $assignedToUserId = null): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'INSERT INTO household_task_instances (task_id, due_at, assigned_to_user_id) VALUES (:task_id, :due_at, :assigned_to_user_id)'
        );
        $stmt->execute(['task_id' => $taskId, 'due_at' => $dueAt, 'assigned_to_user_id' => $assignedToUserId]);

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
     * create/update/complete -- the instance's own fields (including which
     * specific assignee's copy this is, if any) plus its parent
     * definition's, the way listForHousehold()/listAssignedToUser() already
     * return each row (see their own docblocks). Doesn't include the full
     * assignee list -- TaskService attaches that itself, the same bulk way
     * the list methods do, since it needs the same lookup either way.
     */
    public function findByIdWithTaskInfo(int $id): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT household_task_instances.*, household_tasks.household_id, household_tasks.title,
                    household_tasks.description, household_tasks.assignment_mode, household_tasks.priority,
                    household_tasks.recurrence_frequency, household_tasks.recurrence_interval,
                    assignee.username AS assigned_to_username
             FROM household_task_instances
             INNER JOIN household_tasks ON household_tasks.id = household_task_instances.task_id
             LEFT JOIN users AS assignee ON assignee.id = household_task_instances.assigned_to_user_id
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

    public function countForTask(int $taskId): int
    {
        $stmt = Connection::get()->prepare('SELECT COUNT(*) AS total FROM household_task_instances WHERE task_id = :task_id');
        $stmt->execute(['task_id' => $taskId]);

        return (int) $stmt->fetch()['total'];
    }

    /**
     * existsForTaskAndDate(...) - $dueAt and $assignedToUserId are both
     * compared with MySQL/MariaDB's NULL-safe `<=>` operator (plain `=`
     * never matches NULL against NULL): $assignedToUserId since a shared
     * ('anyone'-mode) instance's is itself NULL, $dueAt so this stays
     * correct if ever called for an open-ended task -- in practice cron
     * (the only caller) never does, since it only generates recurring
     * occurrences, which always have a real due date.
     */
    public function existsForTaskAndDate(int $taskId, ?string $dueAt, ?int $assignedToUserId): bool
    {
        $stmt = Connection::get()->prepare(
            'SELECT 1 FROM household_task_instances
             WHERE task_id = :task_id AND due_at <=> :due_at AND assigned_to_user_id <=> :assigned_to_user_id
             LIMIT 1'
        );
        $stmt->execute(['task_id' => $taskId, 'due_at' => $dueAt, 'assigned_to_user_id' => $assignedToUserId]);

        return $stmt->fetch() !== false;
    }

    public function updateDueAt(int $id, ?string $dueAt): void
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

    /**
     * markSkipped(...) - same completed_at/completed_by_user_id/notes
     * columns as markDone() (migration `0012`'s own comment explains why
     * there's no separate skipped_at/skipped_by_user_id) -- $notes here is
     * always a real, non-empty explanation, since TaskService::
     * skipInstance() requires one before ever calling this.
     */
    public function markSkipped(int $id, int $skippedByUserId, string $notes): void
    {
        $stmt = Connection::get()->prepare(
            "UPDATE household_task_instances
             SET status = 'skipped', completed_at = NOW(), completed_by_user_id = :completed_by_user_id, notes = :notes
             WHERE id = :id"
        );
        $stmt->execute(['completed_by_user_id' => $skippedByUserId, 'notes' => $notes, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = Connection::get()->prepare('DELETE FROM household_task_instances WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * listForHousehold(...) - one row per *task*, not per instance: cron's
     * own lookahead window (bin/generate_task_instances.php) can leave a
     * recurring task with several pending instances at once (its next few
     * upcoming occurrences, or a real backlog if it's fallen behind), but
     * this household-wide overview only ever shows the single soonest-due
     * one -- "the root task the instances are generated from" -- per
     * (task, assignee) pair, via the NOT EXISTS below (an earlier-or-equal
     * pending instance for the same task_id/assigned_to_user_id). An
     * 'everyone'-mode task's several concurrent per-assignee copies are
     * separate (task_id, assigned_to_user_id) pairs, so each assignee still
     * gets their own row here -- only *that assignee's own* future
     * occurrences collapse down to one. Completing the shown instance
     * reveals whichever one was next behind it on the following load, so a
     * fallen-behind chore is still addressable one at a time, just not all
     * shown in the tab at once. Each row carries its parent definition's
     * fields, which specific assignee's copy this row is (if 'everyone'
     * mode), and a completion-history summary (count + most recent date)
     * computed from that task's *other*, done instances -- via correlated
     * subqueries rather than a join, since the main result set is filtered
     * to pending while the summary needs to see done ones too.
     *
     * ORDER BY bubbles every open-ended (NULL due_at) instance to the top,
     * highest priority first -- see self::PRIORITY_ORDER_SQL -- ahead of
     * every dated instance, which keep the original ascending-due-date
     * order below them unaffected by priority.
     */
    public function listForHousehold(int $householdId): array
    {
        $stmt = Connection::get()->prepare(
            "SELECT household_task_instances.*, household_tasks.title, household_tasks.description,
                    household_tasks.assignment_mode, household_tasks.priority, household_tasks.recurrence_frequency,
                    household_tasks.recurrence_interval, assignee.username AS assigned_to_username,
                    (SELECT COUNT(*) FROM household_task_instances completed
                        WHERE completed.task_id = household_tasks.id AND completed.status = 'done') AS completion_count,
                    (SELECT MAX(completed.completed_at) FROM household_task_instances completed
                        WHERE completed.task_id = household_tasks.id AND completed.status = 'done') AS last_completed_at
             FROM household_task_instances
             INNER JOIN household_tasks ON household_tasks.id = household_task_instances.task_id
             LEFT JOIN users AS assignee ON assignee.id = household_task_instances.assigned_to_user_id
             WHERE household_tasks.household_id = :household_id AND household_task_instances.status = 'pending'
               AND NOT EXISTS (
                   SELECT 1 FROM household_task_instances earlier
                   WHERE earlier.task_id = household_task_instances.task_id
                     AND earlier.assigned_to_user_id <=> household_task_instances.assigned_to_user_id
                     AND earlier.status = 'pending'
                     AND (earlier.due_at < household_task_instances.due_at
                          OR (earlier.due_at <=> household_task_instances.due_at AND earlier.id < household_task_instances.id))
               )
             ORDER BY (household_task_instances.due_at IS NULL) DESC, " . self::PRIORITY_ORDER_SQL . ",
                      household_task_instances.due_at ASC, household_task_instances.created_at DESC"
        );
        $stmt->execute(['household_id' => $householdId]);

        return $stmt->fetchAll();
    }

    /**
     * listAssignedToUser(...) - the "My Tasks" view: every pending instance
     * that is *this user's own to act on* across every household they
     * belong to -- either a shared 'anyone'-mode instance for a task they're
     * one of the assignees on, or their own personal 'everyone'-mode copy.
     * Same household_members join guard as before the multi-assignee
     * follow-up, for the same reason: a stale assignment shouldn't keep
     * surfacing here after the assignee has since left that household.
     *
     * Same open-ended-bubbles-to-top-by-priority ordering as
     * listForHousehold() -- see self::PRIORITY_ORDER_SQL -- since this is
     * the one place an open-ended task (issue #12's own follow-up) is meant
     * to actually surface: a "no deadline" reminder like "put the new latch
     * on the back gate" is exactly the kind of thing that should jump to
     * the top of *someone's* personal list instead of getting buried under
     * dated chores.
     */
    public function listAssignedToUser(int $userId): array
    {
        $stmt = Connection::get()->prepare(
            "SELECT household_task_instances.*, household_tasks.household_id, households.name AS household_name,
                    household_tasks.title, household_tasks.description, household_tasks.assignment_mode,
                    household_tasks.priority, household_tasks.recurrence_frequency, household_tasks.recurrence_interval,
                    (SELECT COUNT(*) FROM household_task_instances completed
                        WHERE completed.task_id = household_tasks.id AND completed.status = 'done') AS completion_count,
                    (SELECT MAX(completed.completed_at) FROM household_task_instances completed
                        WHERE completed.task_id = household_tasks.id AND completed.status = 'done') AS last_completed_at
             FROM household_task_instances
             INNER JOIN household_tasks ON household_tasks.id = household_task_instances.task_id
             INNER JOIN households ON households.id = household_tasks.household_id
             INNER JOIN household_task_assignees
                 ON household_task_assignees.task_id = household_tasks.id
                AND household_task_assignees.user_id = :user_id_assignee
             INNER JOIN household_members
                 ON household_members.household_id = household_tasks.household_id
                AND household_members.user_id = household_task_assignees.user_id
             WHERE household_task_instances.status = 'pending'
               AND (household_task_instances.assigned_to_user_id IS NULL OR household_task_instances.assigned_to_user_id = :user_id_instance)
             GROUP BY household_task_instances.id
             ORDER BY (household_task_instances.due_at IS NULL) DESC, " . self::PRIORITY_ORDER_SQL . ",
                      household_task_instances.due_at ASC, household_task_instances.created_at DESC"
        );
        $stmt->execute(['user_id_assignee' => $userId, 'user_id_instance' => $userId]);

        return $stmt->fetchAll();
    }

    /**
     * purgeResolvedOlderThan(...)/purgeExpiredPendingOlderThan(...) - the
     * daily cron script's cleanup half (bin/generate_task_instances.php):
     * an old completed *or skipped* instance is pure history past a point
     * -- both are equally "resolved," just with a different outcome -- and
     * a pending instance nobody ever completed shouldn't clutter the list
     * forever either. Return the number of rows removed, for the script's
     * own log output.
     */
    public function purgeResolvedOlderThan(int $days): int
    {
        $stmt = Connection::get()->prepare(
            "DELETE FROM household_task_instances WHERE status IN ('done', 'skipped') AND completed_at < (NOW() - INTERVAL :days DAY)"
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

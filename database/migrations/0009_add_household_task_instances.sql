-- Splits household_tasks into a pure definition (a recurring rule, or a
-- one-off) plus household_task_instances, one row per concrete occurrence
-- (issue #12 follow-up). Until now a recurring task was a single mutable
-- row: completing it advanced next_due_at in place, so there was never more
-- than one visible occurrence, and nothing ever created a new one on its
-- own -- an unaddressed chore just sat there increasingly overdue forever.
-- With instances as their own rows, a daily cron script
-- (bin/generate_task_instances.php) can proactively populate the next
-- several days of occurrences for every recurring definition (so falling
-- behind shows up as a real backlog of individually-completable rows, not
-- one stuck row) and purge old completed/expired ones -- see "Task/chore
-- tracking" in php-app/README.md.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS household_task_instances (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id INT UNSIGNED NOT NULL,
    due_at DATE NOT NULL,
    status ENUM('pending', 'done') NOT NULL DEFAULT 'pending',
    completed_at TIMESTAMP NULL DEFAULT NULL,
    completed_by_user_id INT UNSIGNED NULL,
    notes VARCHAR(2000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Guards cron re-runs (and any gap-catch-up loop) against ever
    -- generating two instances of the same task due on the same day.
    UNIQUE KEY uniq_household_task_instances_task_due (task_id, due_at),
    KEY idx_household_task_instances_status_due (status, due_at),
    CONSTRAINT fk_household_task_instances_task_id FOREIGN KEY (task_id) REFERENCES household_tasks (id) ON DELETE CASCADE,
    CONSTRAINT fk_household_task_instances_completed_by_user_id FOREIGN KEY (completed_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: give every existing task its current state as a single
-- instance -- a 'done' one for an already-completed one-off task (pulling
-- completion details from its most recent household_task_completions row,
-- if any), otherwise a 'pending' one at its due date. Individual historical
-- *completion events* before this migration aren't replayed as separate
-- instances -- household_task_completions never stored a due date alongside
-- each past completion, so there's nothing faithful to reconstruct
-- per-event; only each task's current state carries forward.
INSERT INTO household_task_instances (task_id, due_at, status, completed_at, completed_by_user_id, notes)
SELECT
    household_tasks.id,
    COALESCE(household_tasks.next_due_at, CURDATE()),
    IF(household_tasks.status = 'done', 'done', 'pending'),
    last_completion.completed_at,
    last_completion.completed_by_user_id,
    last_completion.notes
FROM household_tasks
LEFT JOIN (
    SELECT c1.*
    FROM household_task_completions c1
    INNER JOIN (
        SELECT task_id, MAX(completed_at) AS max_completed_at
        FROM household_task_completions
        GROUP BY task_id
    ) c2 ON c2.task_id = c1.task_id AND c2.max_completed_at = c1.completed_at
) AS last_completion ON last_completion.task_id = household_tasks.id;

DROP TABLE household_task_completions;

ALTER TABLE household_tasks
    DROP COLUMN status,
    CHANGE COLUMN next_due_at start_date DATE NULL;

UPDATE household_tasks SET start_date = CURDATE() WHERE start_date IS NULL;

ALTER TABLE household_tasks MODIFY start_date DATE NOT NULL;

UPDATE schema_version SET version = '0.7.0' WHERE id = 1;

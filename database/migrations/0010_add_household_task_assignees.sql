-- Multiple assignees per task, with an anyone/everyone completion mode.
--
-- household_task_assignees replaces household_tasks.assigned_to_user_id
-- (a single nullable column) with a proper many-to-many table. The new
-- household_tasks.assignment_mode decides what a task with 2+ assignees
-- means:
--   - 'anyone' (the default -- and the *only* meaningful mode for 0/1
--     assignees): one shared instance per occurrence, same as before this
--     migration -- whoever completes it first completes it for everyone.
--   - 'everyone': each assignee needs to complete their own copy of the
--     occurrence. Modeled by generating one household_task_instances row
--     *per assignee* (assigned_to_user_id set on the instance itself, new
--     below) rather than inventing a separate per-person completion
--     table -- every existing complete/delete/list code path already
--     operates per-instance-row and needs no change at all to support this.
--
-- household_task_instances.assigned_to_user_id is NULL for a shared
-- ('anyone'-mode, or 0/1-assignee) instance, and set to a specific
-- assignee's id for their own personal copy in 'everyone' mode. Its
-- UNIQUE KEY includes this column so 'everyone' mode's per-assignee rows
-- for the same occurrence are each guaranteed unique -- but MySQL/MariaDB
-- unique indexes treat every NULL as distinct from every other NULL, so
-- that guarantee does NOT extend to preventing two *shared* instances for
-- the same occurrence. In practice that's fine: generation always
-- exists-checks before inserting (see HouseholdTaskInstanceRepository::
-- existsForTaskAndDate()) and there's a single sequential daily cron
-- writer, not concurrent ones -- the unique key here is a backstop for the
-- case (per-assignee rows) where it actually matters, not the only thing
-- standing between the app and a duplicate.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS household_task_assignees (
    task_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (task_id, user_id),
    KEY idx_household_task_assignees_user_id (user_id),
    CONSTRAINT fk_household_task_assignees_task_id FOREIGN KEY (task_id) REFERENCES household_tasks (id) ON DELETE CASCADE,
    CONSTRAINT fk_household_task_assignees_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO household_task_assignees (task_id, user_id)
SELECT id, assigned_to_user_id FROM household_tasks WHERE assigned_to_user_id IS NOT NULL;

ALTER TABLE household_tasks
    ADD COLUMN assignment_mode ENUM('anyone', 'everyone') NOT NULL DEFAULT 'anyone' AFTER description,
    DROP FOREIGN KEY fk_household_tasks_assigned_to_user_id,
    DROP COLUMN assigned_to_user_id;

ALTER TABLE household_task_instances
    ADD COLUMN assigned_to_user_id INT UNSIGNED NULL AFTER due_at,
    ADD CONSTRAINT fk_household_task_instances_assigned_to_user_id FOREIGN KEY (assigned_to_user_id) REFERENCES users (id) ON DELETE SET NULL,
    DROP INDEX uniq_household_task_instances_task_due,
    ADD UNIQUE KEY uniq_household_task_instances_task_due_assignee (task_id, due_at, assigned_to_user_id);

UPDATE schema_version SET version = '0.8.0' WHERE id = 1;

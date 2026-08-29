-- Household task/chore tracker (issue #12): one-off tasks and recurring
-- chores (daily/weekly/monthly/annual, on an N-interval), assignable to any
-- household member, with an append-only completion history.
--
-- `source_type`/`source_id` are a deliberately unenforced (no FK) polymorphic
-- reference back to whatever originated the task -- e.g. a future meeting
-- (issue #8) or home-improvement project (issue #11) task. Neither of those
-- tables exists yet; this just reserves the columns per issue #12's own
-- consolidation recommendation, so #8/#11 can link into this one shared
-- system instead of each growing their own bespoke task table.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS household_tasks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    household_id INT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    description VARCHAR(2000) NULL,
    assigned_to_user_id INT UNSIGNED NULL,
    status ENUM('open', 'in_progress', 'done') NOT NULL DEFAULT 'open',
    recurrence_frequency ENUM('daily', 'weekly', 'monthly', 'annual') NULL,
    recurrence_interval SMALLINT UNSIGNED NULL,
    next_due_at DATE NULL,
    source_type VARCHAR(50) NULL,
    source_id INT UNSIGNED NULL,
    created_by_user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_household_tasks_household_id (household_id),
    KEY idx_household_tasks_assigned_to_user_id (assigned_to_user_id),
    CONSTRAINT fk_household_tasks_household_id FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
    CONSTRAINT fk_household_tasks_assigned_to_user_id FOREIGN KEY (assigned_to_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_household_tasks_created_by_user_id FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only: a recurring chore's value is in seeing it *was* done
-- regularly, not just when it's next due.
CREATE TABLE IF NOT EXISTS household_task_completions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id INT UNSIGNED NOT NULL,
    completed_by_user_id INT UNSIGNED NOT NULL,
    completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes VARCHAR(2000) NULL,
    PRIMARY KEY (id),
    KEY idx_household_task_completions_task_id (task_id),
    CONSTRAINT fk_household_task_completions_task_id FOREIGN KEY (task_id) REFERENCES household_tasks (id) ON DELETE CASCADE,
    CONSTRAINT fk_household_task_completions_completed_by_user_id FOREIGN KEY (completed_by_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '0.6.0' WHERE id = 1;

-- Home improvement projects (issue #11): a project's own lifecycle
-- (status, estimated-vs-actual cost, target date) is real enough to be its
-- own entity -- but a project's own tasks are plain household_tasks (#12),
-- created with source_type = 'home_improvement_project' and source_id =
-- this project's id, reusing #12's assignment/status/completion-history
-- model rather than a bespoke task table of its own. source_type/source_id
-- already exist on household_tasks (migration 0008) reserved for exactly
-- this; no FK from them back to this table, same deliberately-unenforced
-- polymorphic-reference reasoning as that migration's own comment (a
-- deleted project leaves its tasks behind as ordinary, still-valid tasks
-- rather than cascading -- see TaskService/HomeImprovementService's own
-- docblocks).
--
-- Maintenance (the recurring counterpart) needs no table at all here -- a
-- maintenance item is just a recurring household_task tagged source_type =
-- 'maintenance' (source_id left NULL, since it doesn't source from a
-- project or anything else -- the marker alone is what lets the
-- Maintenance view filter these out from other recurring chores).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS home_improvement_projects (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    household_id INT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    description VARCHAR(2000) NULL,
    status ENUM('idea', 'planned', 'in_progress', 'completed', 'abandoned') NOT NULL DEFAULT 'idea',
    estimated_cost DECIMAL(10, 2) NULL,
    actual_cost DECIMAL(10, 2) NULL,
    target_date DATE NULL,
    created_by_user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_home_improvement_projects_household_id (household_id),
    CONSTRAINT fk_home_improvement_projects_household_id FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
    CONSTRAINT fk_home_improvement_projects_created_by_user_id FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '0.13.0' WHERE id = 1;

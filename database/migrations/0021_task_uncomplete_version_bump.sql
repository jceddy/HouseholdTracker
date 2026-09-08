-- Version-only migration: adding POST /households/tasks/uncomplete (the
-- undo side of /complete, backing the web UI's 5-second "Undo" toast) has
-- no schema change of its own -- it reuses household_task_instances' own
-- existing status/completed_at/completed_by_user_id columns -- but every
-- merged change bumps VERSION now, and this carries that bump.
SET NAMES utf8mb4;

UPDATE schema_version SET version = '0.19.0' WHERE id = 1;

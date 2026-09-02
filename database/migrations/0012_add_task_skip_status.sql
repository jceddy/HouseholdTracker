-- Skipping a recurring chore's occurrence (issue #12 follow-up): until now
-- the only way to resolve an undone recurring instance without completing
-- it was to delete it outright (POST /households/tasks/delete), which
-- leaves no record of what happened or why. A third status, 'skipped',
-- lets a household member explicitly say "this occurrence isn't
-- happening" while keeping a note explaining why (e.g. "didn't walk the
-- dog -- there was a tornado") -- see TaskService::skipInstance()'s own
-- docblock for the full design (recurring-only, note required).
--
-- Reuses completed_at/completed_by_user_id/notes rather than adding
-- skipped_at/skipped_by_user_id columns -- those three already mean "when
-- this instance was resolved, by whom, with what note" regardless of
-- which terminal status it resolved to; a fourth resolution state
-- wouldn't need new columns either. TaskService::skipInstance() is the
-- only thing that treats the note as required rather than optional.
SET NAMES utf8mb4;

ALTER TABLE household_task_instances
    MODIFY COLUMN status ENUM('pending', 'done', 'skipped') NOT NULL DEFAULT 'pending';

UPDATE schema_version SET version = '0.10.0' WHERE id = 1;

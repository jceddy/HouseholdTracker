-- Open-ended tasks (issue #12 follow-up): a one-off task ("put the new
-- latch on the back gate") doesn't always have a real deadline. Until now
-- every instance needed a due_at -- omitting one on creation just defaulted
-- it to today, which isn't the same thing as "no deadline at all" and gave
-- every one-off task a due date whether or not one made sense.
--
-- household_task_instances.due_at becomes nullable: NULL means "no
-- deadline". Only a one-off task's instance is ever created with a NULL
-- due_at -- a recurring task's occurrences always need a real date to
-- anchor RecurrenceCalculator's advancement, so TaskService still requires
-- one there (defaulting to today if omitted, same as before this
-- migration). The daily cron script (bin/generate_task_instances.php) only
-- ever touches recurring definitions, so a NULL due_at is never something
-- it has to reason about; the same goes for purgeExpiredPendingOlderThan()
-- -- comparing a NULL due_at against "< CURDATE() - INTERVAL" is NULL
-- (never true) in SQL, so an open-ended task's instance is correctly never
-- swept up as "expired" just for having sat around a long time, with no
-- code change needed there.
--
-- household_tasks.priority (low/medium/high/critical, nullable) lets an
-- open-ended task be triaged and sorted -- see TaskService's own docblock
-- and HouseholdTaskInstanceRepository::listForHousehold()/
-- listAssignedToUser() for how a NULL due_at instance bubbles to the top
-- of a task list, ordered highest priority first. A dated or recurring
-- task can technically have a priority set too (nothing enforces
-- otherwise), it just isn't used to reorder anything outside the
-- no-deadline group.
SET NAMES utf8mb4;

ALTER TABLE household_tasks
    ADD COLUMN priority ENUM('low', 'medium', 'high', 'critical') NULL AFTER assignment_mode;

ALTER TABLE household_task_instances
    MODIFY COLUMN due_at DATE NULL;

UPDATE schema_version SET version = '0.9.0' WHERE id = 1;

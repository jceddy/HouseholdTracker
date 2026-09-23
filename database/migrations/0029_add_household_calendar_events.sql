-- Household calendar (issue #13). A three-tier visibility model, unlike
-- household_notes' plain private/public split -- 'busy' sits between them:
-- another member sees the time slot is blocked, but none of the details
-- (title/description/location/responsible party/who created it). All
-- redaction happens server-side at query time (HouseholdService::
-- listCalendarEvents()), never client-side -- see "Household calendar" in
-- php-app/README.md.
--
-- Same "creator can edit/delete their own, not just any member" permission
-- model as household_notes (created_by_user_id, not author_user_id, to
-- match the naming convention every other tracker here already uses) --
-- this table carries private content, unlike pets/tasks/shopping/staples.
--
-- starts_at/ends_at are plain DATETIME, not TIMESTAMP -- a single
-- household-local wall-clock time shared by every member, the same
-- no-per-user-timezone-conversion assumption every other date/time column
-- in this schema already makes (see household_task_instances.due_at).
--
-- responsible_user_id (nullable -- the person accountable for this event,
-- which may differ from whoever entered it) uses ON DELETE SET NULL, like
-- household_tasks.assigned_to_user_id -- deleting that user shouldn't
-- delete the event itself. created_by_user_id, the actual owner for
-- privacy/edit-permission purposes, cascades like every other
-- created_by_user_id column here.
--
-- No recurrence support yet (single-occurrence events only) and no
-- multi-attendee list (one responsible party, not several) -- both
-- explicit open questions on the issue, deferred until a real need shows
-- up rather than guessing at a recurrence/attendee model now.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS household_calendar_events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    household_id INT UNSIGNED NOT NULL,
    created_by_user_id INT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    description VARCHAR(2000) NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    location VARCHAR(255) NULL,
    responsible_user_id INT UNSIGNED NULL,
    visibility ENUM('private', 'busy', 'public') NOT NULL DEFAULT 'public',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_household_calendar_events_household_range (household_id, starts_at, ends_at),
    CONSTRAINT fk_household_calendar_events_household_id FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
    CONSTRAINT fk_household_calendar_events_created_by_user_id FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_household_calendar_events_responsible_user_id FOREIGN KEY (responsible_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '0.27.0' WHERE id = 1;

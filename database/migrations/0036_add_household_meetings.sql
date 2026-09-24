-- Household meetings (issue #8): when a meeting happened, who was there,
-- and notes on decisions made/issues discussed. A meeting's own tasks
-- ("action items") are *not* a bespoke table here -- they're plain
-- household_tasks (issue #12), created with source_type = 'meeting',
-- source_id = this table's id, the exact same "reuse the shared task
-- system via the polymorphic source_type/source_id tag" shape issue #11's
-- home improvement projects already established (see
-- 0015_add_home_improvement_projects.sql's own comment). No schema change
-- needed on household_tasks itself -- source_type is already a plain
-- VARCHAR(50), not an ENUM (see 0008's own comment).
--
-- Same "no privacy tiers, any member can add/edit/remove" permission
-- model as pets/contacts/calendar-public-events -- a meeting log is
-- shared household information, not one member's private content.
--
-- notes is TEXT (not a capped VARCHAR) like household_notes.body --
-- meeting notes (decisions made + issues discussed) can run long, and v1
-- settles the issue's own "separate structured issues list vs. one
-- freeform field" open question in favor of the single field, same as
-- every other freeform notes column in this schema.
--
-- household_meeting_attendees is a plain (meeting_id, user_id) composite-
-- key join table with no id/timestamps of its own -- same shape as
-- household_task_assignees, since it's always replaced wholesale
-- (HouseholdMeetingRepository::replaceAttendees()) rather than edited row
-- by row.
--
-- Deleting a meeting does NOT cascade-delete its linked tasks -- they
-- simply keep existing as ordinary tasks, source_type/source_id now
-- pointing at nothing in particular, the same behavior
-- HomeImprovementService::deleteProject() already settled on for a
-- deleted project's own tasks.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS household_meetings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    household_id INT UNSIGNED NOT NULL,
    occurred_at DATETIME NOT NULL,
    notes TEXT NULL,
    created_by_user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_household_meetings_household_id (household_id),
    CONSTRAINT fk_household_meetings_household_id FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
    CONSTRAINT fk_household_meetings_created_by_user_id FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS household_meeting_attendees (
    meeting_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (meeting_id, user_id),
    KEY idx_household_meeting_attendees_user_id (user_id),
    CONSTRAINT fk_household_meeting_attendees_meeting_id FOREIGN KEY (meeting_id) REFERENCES household_meetings (id) ON DELETE CASCADE,
    CONSTRAINT fk_household_meeting_attendees_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '0.31.0' WHERE id = 1;

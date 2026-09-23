-- Household polls (issue #27): a lightweight "ask the household, let them
-- vote" mechanism for any decision -- what's for dinner, which movie, where
-- to go on vacation -- not tied to any other tracker.
--
-- Same "no privacy tiers, any member can create/vote/close/delete" model as
-- pets/contacts/meetings -- see "Household roles and permissions" in
-- php-app/README.md.
--
-- Votes are NOT anonymous -- household_poll_votes records who voted for
-- what, and results always show individual voters, matching this app's
-- general "shared household information, not private content" default
-- (contrast with household_notes/private calendar events).
--
-- Options are fixed at creation (no crowd-sourced additions in v1) and
-- recurring/periodic polls are out of scope for v1 -- a poll is a one-off,
-- created manually (see the issue's own open questions).
--
-- allow_multiple_selections controls single- vs multi-select; votes are
-- always submitted as the caller's full desired option_id set for a poll
-- and wholesale-replaced (HouseholdPollRepository::replaceVotes()), the
-- same "replace, don't diff" shape household_meeting_attendees/
-- household_task_assignees already use -- this doubles as "change my vote"
-- with no separate un-vote action needed.
--
-- closes_at is an optional auto-expiry (NULL = open until manually closed).
-- Like due_at/is-it-overdue elsewhere in this app, "is this poll still
-- open" is a plain comparison against the current time, computed where
-- it's needed (HouseholdPollService rejects a vote against a
-- closed/expired poll; the frontend computes the same thing for display,
-- same as isTaskOverdue()) rather than a value stored on the row.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS household_polls (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    household_id INT UNSIGNED NOT NULL,
    created_by_user_id INT UNSIGNED NOT NULL,
    question VARCHAR(500) NOT NULL,
    allow_multiple_selections TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    closes_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_household_polls_household_id (household_id),
    CONSTRAINT fk_household_polls_household_id FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
    CONSTRAINT fk_household_polls_created_by_user_id FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS household_poll_options (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    poll_id INT UNSIGNED NOT NULL,
    option_text VARCHAR(200) NOT NULL,
    display_order INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_household_poll_options_poll_id (poll_id),
    CONSTRAINT fk_household_poll_options_poll_id FOREIGN KEY (poll_id) REFERENCES household_polls (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS household_poll_votes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    poll_id INT UNSIGNED NOT NULL,
    option_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    voted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_household_poll_votes (poll_id, option_id, user_id),
    KEY idx_household_poll_votes_option_id (option_id),
    KEY idx_household_poll_votes_poll_user (poll_id, user_id),
    CONSTRAINT fk_household_poll_votes_poll_id FOREIGN KEY (poll_id) REFERENCES household_polls (id) ON DELETE CASCADE,
    CONSTRAINT fk_household_poll_votes_option_id FOREIGN KEY (option_id) REFERENCES household_poll_options (id) ON DELETE CASCADE,
    CONSTRAINT fk_household_poll_votes_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '0.32.0' WHERE id = 1;

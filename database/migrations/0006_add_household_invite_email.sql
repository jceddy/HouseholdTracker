-- Extends household_invites (issue #33) to support inviting an email address
-- with no account yet -- the invite doubles as that person's registration
-- link. See HouseholdService::linkPendingInvitesForEmail(), called once that
-- email is verified during registration, for how a pending email-only invite
-- becomes an ordinary existing-user invite with no separate acceptance path.
SET NAMES utf8mb4;

ALTER TABLE household_invites
    MODIFY invited_user_id INT UNSIGNED NULL,
    ADD COLUMN invited_email VARCHAR(255) NULL AFTER invited_user_id,
    ADD INDEX idx_household_invites_invited_email (invited_email);

UPDATE schema_version SET version = '0.4.0' WHERE id = 1;

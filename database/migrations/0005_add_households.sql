-- Households (issue #5): a household as a first-class entity multiple users can
-- belong to, plus an invite flow for adding them.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS households (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    created_by_user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_households_created_by_user_id (created_by_user_id),
    CONSTRAINT fk_households_created_by_user_id FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A user's membership (and role) in a household lives here rather than a column
-- on users, so it's not locked to "one household per user" -- see issue #5's own
-- open questions.
CREATE TABLE IF NOT EXISTS household_members (
    household_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    role ENUM('owner', 'member') NOT NULL DEFAULT 'member',
    joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (household_id, user_id),
    KEY idx_household_members_user_id (user_id),
    CONSTRAINT fk_household_members_household_id FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
    CONSTRAINT fk_household_members_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pending invites to an existing registered user (invite-by-email-to-an-unregistered-
-- address, from issue #5's own open questions, is deferred -- not built here).
CREATE TABLE IF NOT EXISTS household_invites (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    household_id INT UNSIGNED NOT NULL,
    invited_user_id INT UNSIGNED NOT NULL,
    invited_by_user_id INT UNSIGNED NOT NULL,
    status ENUM('pending', 'accepted', 'declined') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_household_invites_household_id (household_id),
    KEY idx_household_invites_invited_user_id (invited_user_id),
    CONSTRAINT fk_household_invites_household_id FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
    CONSTRAINT fk_household_invites_invited_user_id FOREIGN KEY (invited_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_household_invites_invited_by_user_id FOREIGN KEY (invited_by_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '0.3.0' WHERE id = 1;

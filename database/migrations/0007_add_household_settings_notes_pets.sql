-- Household settings, notes, and pets (issue #7).
--
-- Settings: no new table for now -- the only setting so far is the
-- household's own name, already a column on `households` (see #5's
-- migration); HouseholdService::updateSettings() just updates it in place.
-- Revisit with a dedicated key/value table if a second setting shows up.
--
-- Pets: `vet_contact_id` is deliberately NOT included yet -- issue #16
-- (household contacts) doesn't exist yet for it to reference. Added via a
-- follow-up migration once #16 ships (see "Household pets" in
-- php-app/README.md).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS household_notes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    household_id INT UNSIGNED NOT NULL,
    author_user_id INT UNSIGNED NOT NULL,
    visibility ENUM('private', 'public') NOT NULL DEFAULT 'private',
    body TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_household_notes_household_id (household_id),
    KEY idx_household_notes_author_user_id (author_user_id),
    CONSTRAINT fk_household_notes_household_id FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
    CONSTRAINT fk_household_notes_author_user_id FOREIGN KEY (author_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS household_pets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    household_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    species VARCHAR(100) NULL,
    breed VARCHAR(100) NULL,
    birthday DATE NULL,
    notes VARCHAR(2000) NULL,
    created_by_user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_household_pets_household_id (household_id),
    CONSTRAINT fk_household_pets_household_id FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
    CONSTRAINT fk_household_pets_created_by_user_id FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '0.5.0' WHERE id = 1;

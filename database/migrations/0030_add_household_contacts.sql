-- Household contacts (issue #16), split off from #7's pets -- a
-- general-purpose address book for the household's important contacts
-- (vet, doctor, plumber, insurance agent, ...) rather than a field bolted
-- onto whichever tracker happened to need one first. category is
-- deliberately freeform text, not an ENUM -- the same "open-ended list,
-- not a small fixed set worth hardcoding" reasoning household_shopping_
-- items.category and household_staple_items.category already use.
--
-- No privacy tiers -- like household_pets, this is shared reference data:
-- every member sees the full list, and any member may add/edit/remove a
-- contact.
--
-- household_pets.vet_contact_id (added in the next migration, 0031) will
-- reference this table -- see that migration's own comment.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS household_contacts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    household_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    category VARCHAR(50) NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(255) NULL,
    address VARCHAR(255) NULL,
    notes VARCHAR(2000) NULL,
    created_by_user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_household_contacts_household_id (household_id),
    CONSTRAINT fk_household_contacts_household_id FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
    CONSTRAINT fk_household_contacts_created_by_user_id FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '0.28.0' WHERE id = 1;

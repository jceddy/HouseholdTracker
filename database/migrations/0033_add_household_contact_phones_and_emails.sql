-- Multiple phone numbers/email addresses per contact, each labeled
-- home/mobile/work (issue #16 follow-up) -- household_contacts.phone/
-- email (single nullable columns) replaces with two child tables, the
-- same "replace a single column with a proper one-to-many table,
-- backfilling existing data" shape household_task_assignees (migration
-- 0010) already used for assigned_to_user_id.
--
-- No created_at/updated_at on either table -- both are always edited as a
-- whole (HouseholdContactRepository::replacePhones()/replaceEmails(),
-- delete-then-reinsert on every contact save, same "wholesale replace,
-- not a diff" approach household_task_assignees already uses), so a
-- per-row timestamp would never reflect anything meaningful.
--
-- label is a closed three-value ENUM, not freeform text like
-- household_contacts.category -- unlike a contact's own category (an
-- open-ended list of real-world roles), "home/mobile/work" is a fixed,
-- well-known set with no reason to expect a fourth value.
--
-- household_contacts.address also widens here (255 -> 500 chars) to
-- comfortably hold a multi-line address now that the web UI renders it as
-- a <textarea> instead of a single-line <input>.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS household_contact_phones (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    contact_id INT UNSIGNED NOT NULL,
    label ENUM('home', 'mobile', 'work') NOT NULL DEFAULT 'home',
    phone VARCHAR(50) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_household_contact_phones_contact_id (contact_id),
    CONSTRAINT fk_household_contact_phones_contact_id FOREIGN KEY (contact_id) REFERENCES household_contacts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS household_contact_emails (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    contact_id INT UNSIGNED NOT NULL,
    label ENUM('home', 'mobile', 'work') NOT NULL DEFAULT 'home',
    email VARCHAR(255) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_household_contact_emails_contact_id (contact_id),
    CONSTRAINT fk_household_contact_emails_contact_id FOREIGN KEY (contact_id) REFERENCES household_contacts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO household_contact_phones (contact_id, label, phone)
SELECT id, 'home', phone FROM household_contacts WHERE phone IS NOT NULL AND phone <> '';

INSERT INTO household_contact_emails (contact_id, label, email)
SELECT id, 'home', email FROM household_contacts WHERE email IS NOT NULL AND email <> '';

ALTER TABLE household_contacts
    DROP COLUMN phone,
    DROP COLUMN email,
    MODIFY COLUMN address VARCHAR(500) NULL;

UPDATE schema_version SET version = '0.30.0' WHERE id = 1;

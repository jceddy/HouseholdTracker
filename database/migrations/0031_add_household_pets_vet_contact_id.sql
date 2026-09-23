-- Wires up household_pets.vet_contact_id (issue #16's own follow-up), left
-- out of 0007 because household_contacts didn't exist yet for it to
-- reference (see that migration's own comment). ON DELETE SET NULL, like
-- household_tasks.assigned_to_user_id -- deleting a contact shouldn't
-- delete the pet that pointed to it, just clear the reference.
SET NAMES utf8mb4;

ALTER TABLE household_pets
    ADD COLUMN vet_contact_id INT UNSIGNED NULL AFTER notes,
    ADD CONSTRAINT fk_household_pets_vet_contact_id FOREIGN KEY (vet_contact_id) REFERENCES household_contacts (id) ON DELETE SET NULL;

UPDATE schema_version SET version = '0.29.0' WHERE id = 1;

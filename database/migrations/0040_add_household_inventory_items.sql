-- Household inventory/asset tracking (issue #23): valuable possessions
-- (appliances, electronics, furniture) with purchase info, warranty
-- expiration, serial numbers, and location -- useful for insurance claims
-- and to know when something's still covered.
--
-- No net-worth/value-tracking rollups here on purpose -- purchase_price is
-- just a plain optional field for reference (e.g. an insurance claim),
-- not summed anywhere; that kind of aggregate belongs in a future finances
-- tracker (issue #9), not this one, per that issue's own open question.
--
-- No automatic warranty-expiration reminder (a #13 calendar event or #12
-- task) for v1 -- a member checks the list manually, per the issue's own
-- "stay something a member has to check manually for v1" framing. Wiring
-- that up automatically is a natural follow-up once it's wanted, not a
-- requirement to ship this at all.
--
-- service_contact_id is a single nullable link to household_contacts (issue
-- #16) for "who services/repairs this" -- the simpler of the two options
-- the issue named (one usual contact vs. a full service-history log),
-- following the exact same shape/reasoning household_pets.vet_contact_id
-- (migration 0031) already established: ON DELETE SET NULL, since deleting
-- a contact shouldn't delete the inventory item that pointed to it.
--
-- No privacy tiers -- like pets/contacts/staples, this is shared household
-- reference data: every member sees the full list, and any member may
-- add/edit/remove an item.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS household_inventory_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    household_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    category VARCHAR(50) NULL,
    purchase_date DATE NULL,
    purchase_price DECIMAL(10,2) NULL,
    warranty_expires_at DATE NULL,
    serial_number VARCHAR(100) NULL,
    location VARCHAR(150) NULL,
    notes VARCHAR(2000) NULL,
    service_contact_id INT UNSIGNED NULL,
    created_by_user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_household_inventory_items_household_id (household_id),
    CONSTRAINT fk_household_inventory_items_household_id FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
    CONSTRAINT fk_household_inventory_items_created_by_user_id FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_household_inventory_items_service_contact_id FOREIGN KEY (service_contact_id) REFERENCES household_contacts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '0.34.0' WHERE id = 1;

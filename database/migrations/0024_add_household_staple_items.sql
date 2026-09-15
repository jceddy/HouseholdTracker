-- Household staples list (issue #66), a companion to the shopping list
-- (issue #24/#65). Distinct table, not a flag on household_shopping_items --
-- a staple is a standing "thing we always keep stocked" definition that gets
-- checked repeatedly (inventory check before a shopping trip), whereas a
-- shopping_items row is a one-off "need to buy this" entry that disappears
-- once purchased. Same "shared household resource, no privacy tiers, any
-- member can act on any row" permission model as pets/shopping list.
--
-- needs_restock is the actual checklist state: false normally, flipped to
-- true when a member checks the pantry and finds it low/out.
-- flagged_by_user_id/flagged_at record who/when, mirroring
-- household_shopping_items.purchased_by_user_id/purchased_at, and use the
-- same ON DELETE SET NULL (not CASCADE) -- deleting the user who happened to
-- flag it shouldn't delete the staple definition itself.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS household_staple_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    household_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    category VARCHAR(50) NULL,
    needs_restock TINYINT(1) NOT NULL DEFAULT 0,
    flagged_by_user_id INT UNSIGNED NULL,
    flagged_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_household_staple_items_household_id (household_id),
    CONSTRAINT fk_household_staple_items_household_id FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
    CONSTRAINT fk_household_staple_items_flagged_by_user_id FOREIGN KEY (flagged_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '0.22.0' WHERE id = 1;

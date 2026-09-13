-- Household shopping list (issue #24). A single shared checklist per
-- household, not per-user -- any member can add an item, and any member can
-- mark any item purchased (same "shared household resource" permission
-- model as pets/notes, see HouseholdService's own docblock).
--
-- `quantity`/`category` are free-text (not numeric/enum) -- "2 lbs" or
-- "a dozen" is just as valid an entry as a bare number, and a fixed
-- category list would need maintaining as households' own grocery habits
-- vary; kept simple for v1 rather than over-engineered.
--
-- No link to a future #9 (spending) transaction and no recurring/favorites
-- template concept yet -- both were explicit open questions on the issue,
-- deferred until a real need for either shows up (#9 doesn't exist yet to
-- link to; plain add-each-time is fine until re-adding the same staples
-- repeatedly becomes an actual complaint).
--
-- purchased_by_user_id uses ON DELETE SET NULL, not CASCADE, mirroring
-- household_task_instances.completed_by_user_id (migration `0009`) --
-- deleting the user who happened to check an item off shouldn't delete the
-- item itself (added_by_user_id, the item's actual owner-ish column, still
-- cascades like every other created_by_user_id in this schema).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS household_shopping_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    household_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    quantity VARCHAR(50) NULL,
    category VARCHAR(50) NULL,
    added_by_user_id INT UNSIGNED NOT NULL,
    purchased_at TIMESTAMP NULL,
    purchased_by_user_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_household_shopping_items_household_id (household_id),
    CONSTRAINT fk_household_shopping_items_household_id FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
    CONSTRAINT fk_household_shopping_items_added_by_user_id FOREIGN KEY (added_by_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_household_shopping_items_purchased_by_user_id FOREIGN KEY (purchased_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '0.21.0' WHERE id = 1;

-- Household meal planning (issue #25): plan meals for the week ahead, tied
-- into the shopping list (issue #24) for ingredients.
--
-- Same "no privacy tiers, any member can add/edit/remove" permission model
-- as pets/contacts/shopping list/staples/meetings/polls -- a meal plan is
-- shared household coordination information, not one member's private
-- content, unlike notes/private calendar events.
--
-- v1 settles the issue's own open questions in favor of the simpler shape:
-- no structured recipe storage (ingredient list/instructions) -- just a
-- meal's title, a freeform ingredients list (one per line, TEXT, mirroring
-- every other freeform column in this schema), and freeform notes. The
-- ingredients tie-in to the shopping list is a manual "add these
-- ingredients" action (HouseholdMealPlanService::
-- addIngredientsToShoppingList()), the same "not automated" choice the
-- issue itself named as the simpler alternative -- reusing
-- HouseholdShoppingItemRepository::create() one row per non-blank
-- ingredient line, the same shape
-- HouseholdService::addNeedingRestockStaplesToShoppingList() already uses
-- for staples. No relationship to issue #10's fitness/nutrition tracking
-- is wired up -- kept fully independent for v1, per the issue's own third
-- open question.
--
-- planned_date is a plain DATE (no time-of-day component -- a meal plan
-- entry is "which day", not "what time"), and meal_type is a genuinely
-- closed set (ENUM), unlike the freeform category/label text columns
-- elsewhere in this schema.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS household_meal_plans (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    household_id INT UNSIGNED NOT NULL,
    planned_date DATE NOT NULL,
    meal_type ENUM('breakfast', 'lunch', 'dinner', 'snack') NOT NULL,
    title VARCHAR(150) NOT NULL,
    ingredients TEXT NULL,
    notes TEXT NULL,
    created_by_user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_household_meal_plans_household_date (household_id, planned_date),
    CONSTRAINT fk_household_meal_plans_household_id FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
    CONSTRAINT fk_household_meal_plans_created_by_user_id FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE schema_version SET version = '0.33.0' WHERE id = 1;

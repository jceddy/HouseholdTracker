<?php

declare(strict_types=1);

namespace HouseholdTracker\Household;

use HouseholdTracker\Repository\HouseholdMealPlanRepository;
use HouseholdTracker\Repository\HouseholdMemberRepository;
use HouseholdTracker\Repository\HouseholdShoppingItemRepository;

/**
 * Household meal planning (issue #25): plan meals for the week ahead, with
 * a manual tie-in to the shopping list (issue #24) for a planned meal's
 * ingredients.
 *
 * Same "no privacy tiers, any member can add/edit/remove" permission model
 * as pets/contacts/shopping list/staples/meetings/polls -- a meal plan is
 * shared household coordination information, not one member's private
 * content.
 *
 * No structured recipe storage and no automated/fitness-tracking tie-in
 * for v1 -- see migration 0038's own comment for the reasoning behind each
 * of the issue's open questions.
 */
final class HouseholdMealPlanService
{
    private const MEAL_TYPES = ['breakfast', 'lunch', 'dinner', 'snack'];
    private const MAX_TITLE_LENGTH = 150;
    private const MAX_INGREDIENTS_LENGTH = 2000;
    private const MAX_NOTES_LENGTH = 2000;

    public function __construct(
        private readonly HouseholdMemberRepository $members,
        private readonly HouseholdMealPlanRepository $mealPlans,
        private readonly HouseholdShoppingItemRepository $shoppingItems,
    ) {
    }

    public function listMealPlans(int $callerId, int $householdId, string $startDate, string $endDate): array
    {
        $this->requireMember($householdId, $callerId);
        $startDate = $this->validateDate($startDate, 'from');
        $endDate = $this->validateDate($endDate, 'to');
        if ($endDate < $startDate) {
            throw new \InvalidArgumentException('to must not be before from.');
        }

        return $this->mealPlans->listForHousehold($householdId, $startDate, $endDate);
    }

    public function createMealPlan(
        int $callerId,
        int $householdId,
        string $plannedDate,
        string $mealType,
        string $title,
        ?string $ingredients,
        ?string $notes
    ): array {
        $this->requireMember($householdId, $callerId);
        $plannedDate = $this->validateDate($plannedDate, 'planned_date');
        $mealType = $this->validateMealType($mealType);
        $title = $this->validateTitle($title);
        $ingredients = $this->validateIngredients($ingredients);
        $notes = $this->validateNotes($notes);

        return $this->mealPlans->create($householdId, $callerId, $plannedDate, $mealType, $title, $ingredients, $notes);
    }

    public function updateMealPlan(
        int $callerId,
        int $mealPlanId,
        string $plannedDate,
        string $mealType,
        string $title,
        ?string $ingredients,
        ?string $notes
    ): array {
        $mealPlan = $this->requireMealPlan($mealPlanId);
        $this->requireMember((int) $mealPlan['household_id'], $callerId);
        $plannedDate = $this->validateDate($plannedDate, 'planned_date');
        $mealType = $this->validateMealType($mealType);
        $title = $this->validateTitle($title);
        $ingredients = $this->validateIngredients($ingredients);
        $notes = $this->validateNotes($notes);

        $this->mealPlans->update((int) $mealPlan['id'], $plannedDate, $mealType, $title, $ingredients, $notes);

        return $this->mealPlans->findById((int) $mealPlan['id']);
    }

    public function deleteMealPlan(int $callerId, int $mealPlanId): void
    {
        $mealPlan = $this->requireMealPlan($mealPlanId);
        $this->requireMember((int) $mealPlan['household_id'], $callerId);
        $this->mealPlans->delete((int) $mealPlan['id']);
    }

    /**
     * addIngredientsToShoppingList(...) - splits the meal plan's freeform
     * ingredients field into one shopping-list item per non-blank line,
     * the same "one row per staple" shape
     * HouseholdService::addNeedingRestockStaplesToShoppingList() already
     * uses. Unlike staples, there's no flag to clear afterward -- a meal
     * plan is a standing record, not a one-shot checklist, so re-running
     * this is a deliberate no-op-safe action a caller can repeat (e.g.
     * after clearing some items back off the shopping list).
     */
    public function addIngredientsToShoppingList(int $callerId, int $mealPlanId): array
    {
        $mealPlan = $this->requireMealPlan($mealPlanId);
        $this->requireMember((int) $mealPlan['household_id'], $callerId);

        $created = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $mealPlan['ingredients']) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $created[] = $this->shoppingItems->create(
                (int) $mealPlan['household_id'],
                $callerId,
                $line,
                null,
                null
            );
        }

        return $created;
    }

    private function requireMealPlan(int $mealPlanId): array
    {
        $mealPlan = $this->mealPlans->findById($mealPlanId);
        if ($mealPlan === null) {
            throw new MealPlanNotFoundException('Meal plan not found.');
        }

        return $mealPlan;
    }

    private function validateDate(string $date, string $fieldName): string
    {
        $parsed = \DateTime::createFromFormat('Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException($fieldName . ' must be in YYYY-MM-DD format.');
        }

        return $date;
    }

    private function validateMealType(string $mealType): string
    {
        if (!in_array($mealType, self::MEAL_TYPES, true)) {
            throw new \InvalidArgumentException('meal_type must be one of: ' . implode(', ', self::MEAL_TYPES) . '.');
        }

        return $mealType;
    }

    private function validateTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            throw new \InvalidArgumentException('A title is required.');
        }
        if (strlen($title) > self::MAX_TITLE_LENGTH) {
            throw new \InvalidArgumentException('The title must be ' . self::MAX_TITLE_LENGTH . ' characters or fewer.');
        }

        return $title;
    }

    private function validateIngredients(?string $ingredients): ?string
    {
        $ingredients = $ingredients !== null ? trim($ingredients) : null;
        $ingredients = $ingredients === '' ? null : $ingredients;
        if ($ingredients !== null && strlen($ingredients) > self::MAX_INGREDIENTS_LENGTH) {
            throw new \InvalidArgumentException('Ingredients must be ' . self::MAX_INGREDIENTS_LENGTH . ' characters or fewer.');
        }

        return $ingredients;
    }

    private function validateNotes(?string $notes): ?string
    {
        $notes = $notes !== null ? trim($notes) : null;
        $notes = $notes === '' ? null : $notes;
        if ($notes !== null && strlen($notes) > self::MAX_NOTES_LENGTH) {
            throw new \InvalidArgumentException('Notes must be ' . self::MAX_NOTES_LENGTH . ' characters or fewer.');
        }

        return $notes;
    }

    private function requireMember(int $householdId, int $userId): void
    {
        if ($this->members->find($householdId, $userId) === null) {
            throw new NotAHouseholdMemberException('You are not a member of this household.');
        }
    }
}

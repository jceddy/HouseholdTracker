<?php

declare(strict_types=1);

namespace HouseholdTracker\Repository;

use HouseholdTracker\Database\Connection;

final class HouseholdMealPlanRepository
{
    public function create(
        int $householdId,
        int $createdByUserId,
        string $plannedDate,
        string $mealType,
        string $title,
        ?string $ingredients,
        ?string $notes
    ): array {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'INSERT INTO household_meal_plans
                (household_id, planned_date, meal_type, title, ingredients, notes, created_by_user_id)
             VALUES (:household_id, :planned_date, :meal_type, :title, :ingredients, :notes, :created_by_user_id)'
        );
        $stmt->execute([
            'household_id' => $householdId,
            'planned_date' => $plannedDate,
            'meal_type' => $mealType,
            'title' => $title,
            'ingredients' => $ingredients,
            'notes' => $notes,
            'created_by_user_id' => $createdByUserId,
        ]);

        return $this->findById((int) $pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM household_meal_plans WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * listForHousehold(...) - every meal plan entry in [startDate, endDate]
     * (both inclusive), ordered by date then meal type so a week-grid
     * frontend can just walk the list in order -- same "server returns it
     * pre-sorted for the view that needs it" approach as
     * HouseholdCalendarEventRepository's own range query.
     */
    public function listForHousehold(int $householdId, string $startDate, string $endDate): array
    {
        $stmt = Connection::get()->prepare(
            "SELECT * FROM household_meal_plans
             WHERE household_id = :household_id AND planned_date BETWEEN :start_date AND :end_date
             ORDER BY planned_date ASC, FIELD(meal_type, 'breakfast', 'lunch', 'dinner', 'snack') ASC, id ASC"
        );
        $stmt->execute([
            'household_id' => $householdId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        return $stmt->fetchAll();
    }

    public function update(
        int $id,
        string $plannedDate,
        string $mealType,
        string $title,
        ?string $ingredients,
        ?string $notes
    ): void {
        $stmt = Connection::get()->prepare(
            'UPDATE household_meal_plans
             SET planned_date = :planned_date, meal_type = :meal_type, title = :title,
                 ingredients = :ingredients, notes = :notes
             WHERE id = :id'
        );
        $stmt->execute([
            'planned_date' => $plannedDate,
            'meal_type' => $mealType,
            'title' => $title,
            'ingredients' => $ingredients,
            'notes' => $notes,
            'id' => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = Connection::get()->prepare('DELETE FROM household_meal_plans WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}

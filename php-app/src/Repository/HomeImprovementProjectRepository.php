<?php

declare(strict_types=1);

namespace HouseholdTracker\Repository;

use HouseholdTracker\Database\Connection;

/**
 * A home improvement project (issue #11) -- its own lifecycle
 * (status/estimated-vs-actual cost/target date), distinct from its linked
 * tasks, which live as plain household_tasks rows (see
 * database/migrations/0015_add_home_improvement_projects.sql's own
 * comment). Deleting a project here doesn't touch those tasks -- they
 * simply stop being findable *as this project's tasks* (their
 * source_type/source_id become stale) but remain ordinary, valid tasks.
 */
final class HomeImprovementProjectRepository
{
    /**
     * FIELD() returns each value's 1-based position in the list (0 if
     * unmatched); ascending on that puts the three "active" statuses
     * first (in_progress, then planned, then idea) ahead of the two
     * "settled" ones (completed, abandoned), newest-first within each.
     */
    private const STATUS_ORDER_SQL = "FIELD(status, 'in_progress', 'planned', 'idea', 'completed', 'abandoned')";

    public function create(
        int $householdId,
        int $createdByUserId,
        string $title,
        ?string $description,
        string $status,
        ?string $estimatedCost,
        ?string $targetDate
    ): array {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'INSERT INTO home_improvement_projects (household_id, title, description, status, estimated_cost, target_date, created_by_user_id)
             VALUES (:household_id, :title, :description, :status, :estimated_cost, :target_date, :created_by_user_id)'
        );
        $stmt->execute([
            'household_id' => $householdId,
            'title' => $title,
            'description' => $description,
            'status' => $status,
            'estimated_cost' => $estimatedCost,
            'target_date' => $targetDate,
            'created_by_user_id' => $createdByUserId,
        ]);

        return $this->findById((int) $pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM home_improvement_projects WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function update(
        int $id,
        string $title,
        ?string $description,
        string $status,
        ?string $estimatedCost,
        ?string $actualCost,
        ?string $targetDate
    ): void {
        $stmt = Connection::get()->prepare(
            'UPDATE home_improvement_projects
             SET title = :title, description = :description, status = :status,
                 estimated_cost = :estimated_cost, actual_cost = :actual_cost, target_date = :target_date
             WHERE id = :id'
        );
        $stmt->execute([
            'title' => $title,
            'description' => $description,
            'status' => $status,
            'estimated_cost' => $estimatedCost,
            'actual_cost' => $actualCost,
            'target_date' => $targetDate,
            'id' => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = Connection::get()->prepare('DELETE FROM home_improvement_projects WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * listForHousehold(...) - every project, active statuses first
     * (newest-first within each) so the household's live projects surface
     * ahead of ones already settled one way or the other -- the frontend
     * groups these into a status board rather than relying on this order
     * alone, but it's a sane fallback either way.
     */
    public function listForHousehold(int $householdId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM home_improvement_projects WHERE household_id = :household_id
             ORDER BY ' . self::STATUS_ORDER_SQL . ', created_at DESC'
        );
        $stmt->execute(['household_id' => $householdId]);

        return $stmt->fetchAll();
    }
}

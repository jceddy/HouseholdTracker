<?php

declare(strict_types=1);

namespace HouseholdTracker\Repository;

use HouseholdTracker\Database\Connection;

final class HouseholdStapleItemRepository
{
    public function create(int $householdId, string $name, ?string $category): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'INSERT INTO household_staple_items (household_id, name, category)
             VALUES (:household_id, :name, :category)'
        );
        $stmt->execute([
            'household_id' => $householdId,
            'name' => $name,
            'category' => $category,
        ]);

        return $this->findById((int) $pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM household_staple_items WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * listAll(...) - flagged-as-needing-restock first (what a member
     * actually wants to act on), then alphabetical within each group, rather
     * than insertion order -- unlike the shopping list, this is a checklist
     * you scan repeatedly, not a running errand log.
     */
    public function listAll(int $householdId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM household_staple_items
             WHERE household_id = :household_id
             ORDER BY needs_restock DESC, name ASC'
        );
        $stmt->execute(['household_id' => $householdId]);

        return $stmt->fetchAll();
    }

    /**
     * listNeedingRestock(...) - the working set for
     * HouseholdService::addNeedingRestockStaplesToShoppingList().
     */
    public function listNeedingRestock(int $householdId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM household_staple_items
             WHERE household_id = :household_id AND needs_restock = 1
             ORDER BY name ASC'
        );
        $stmt->execute(['household_id' => $householdId]);

        return $stmt->fetchAll();
    }

    public function flagNeedsRestock(int $id, int $flaggedByUserId): void
    {
        $stmt = Connection::get()->prepare(
            'UPDATE household_staple_items
             SET needs_restock = 1, flagged_by_user_id = :flagged_by_user_id, flagged_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute(['flagged_by_user_id' => $flaggedByUserId, 'id' => $id]);
    }

    /**
     * unflagNeedsRestock(...) - clears the checklist flag, either a manual
     * "never mind, we still have some" or automatically after
     * addNeedingRestockStaplesToShoppingList() has copied it onto the actual
     * shopping list.
     */
    public function unflagNeedsRestock(int $id): void
    {
        $stmt = Connection::get()->prepare(
            'UPDATE household_staple_items
             SET needs_restock = 0, flagged_by_user_id = NULL, flagged_at = NULL
             WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = Connection::get()->prepare('DELETE FROM household_staple_items WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}

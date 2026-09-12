<?php

declare(strict_types=1);

namespace HouseholdTracker\Repository;

use HouseholdTracker\Database\Connection;

final class HouseholdShoppingItemRepository
{
    public function create(
        int $householdId,
        int $addedByUserId,
        string $name,
        ?string $quantity,
        ?string $category
    ): array {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'INSERT INTO household_shopping_items (household_id, name, quantity, category, added_by_user_id)
             VALUES (:household_id, :name, :quantity, :category, :added_by_user_id)'
        );
        $stmt->execute([
            'household_id' => $householdId,
            'name' => $name,
            'quantity' => $quantity,
            'category' => $category,
            'added_by_user_id' => $addedByUserId,
        ]);

        return $this->findById((int) $pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM household_shopping_items WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * listNeeded(...) - oldest first: items nobody has bought yet, in the
     * order they were added, so the list reads like a running errand list
     * rather than jumping around as new items get added.
     */
    public function listNeeded(int $householdId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM household_shopping_items
             WHERE household_id = :household_id AND purchased_at IS NULL
             ORDER BY created_at ASC'
        );
        $stmt->execute(['household_id' => $householdId]);

        return $stmt->fetchAll();
    }

    /**
     * listRecentlyPurchased(...) - newest first, capped at $limit: purely a
     * "did we already get that" glance-back, not a full purchase history --
     * unbounded would just grow forever with nothing to prune it (unlike
     * household_task_instances, which cron actually deletes old rows from).
     */
    public function listRecentlyPurchased(int $householdId, int $limit = 25): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM household_shopping_items
             WHERE household_id = :household_id AND purchased_at IS NOT NULL
             ORDER BY purchased_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue('household_id', $householdId, \PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function markPurchased(int $id, int $purchasedByUserId): void
    {
        $stmt = Connection::get()->prepare(
            'UPDATE household_shopping_items
             SET purchased_at = NOW(), purchased_by_user_id = :purchased_by_user_id
             WHERE id = :id'
        );
        $stmt->execute(['purchased_by_user_id' => $purchasedByUserId, 'id' => $id]);
    }

    /**
     * markNeeded(...) - the undo side of markPurchased(): a misclick on
     * "purchased" (same reasoning as the task Complete button's own Undo
     * toast) shouldn't require deleting and re-adding the item.
     */
    public function markNeeded(int $id): void
    {
        $stmt = Connection::get()->prepare(
            'UPDATE household_shopping_items
             SET purchased_at = NULL, purchased_by_user_id = NULL
             WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = Connection::get()->prepare('DELETE FROM household_shopping_items WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}

<?php

declare(strict_types=1);

namespace HouseholdTracker\Repository;

use HouseholdTracker\Database\Connection;

final class HouseholdNoteRepository
{
    public function create(int $householdId, int $authorUserId, string $visibility, string $body): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'INSERT INTO household_notes (household_id, author_user_id, visibility, body)
             VALUES (:household_id, :author_user_id, :visibility, :body)'
        );
        $stmt->execute([
            'household_id' => $householdId,
            'author_user_id' => $authorUserId,
            'visibility' => $visibility,
            'body' => $body,
        ]);

        return $this->findById((int) $pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT household_notes.*, users.username AS author_username
             FROM household_notes
             INNER JOIN users ON users.id = household_notes.author_user_id
             WHERE household_notes.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * listVisibleTo(...) - every public note in the household plus the
     * caller's own private ones, never another member's private notes.
     */
    public function listVisibleTo(int $householdId, int $callerId): array
    {
        $stmt = Connection::get()->prepare(
            "SELECT household_notes.*, users.username AS author_username
             FROM household_notes
             INNER JOIN users ON users.id = household_notes.author_user_id
             WHERE household_notes.household_id = :household_id
               AND (household_notes.visibility = 'public' OR household_notes.author_user_id = :caller_id)
             ORDER BY household_notes.created_at DESC"
        );
        $stmt->execute(['household_id' => $householdId, 'caller_id' => $callerId]);

        return $stmt->fetchAll();
    }

    public function update(int $id, string $visibility, string $body): void
    {
        $stmt = Connection::get()->prepare(
            'UPDATE household_notes SET visibility = :visibility, body = :body WHERE id = :id'
        );
        $stmt->execute(['visibility' => $visibility, 'body' => $body, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = Connection::get()->prepare('DELETE FROM household_notes WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}

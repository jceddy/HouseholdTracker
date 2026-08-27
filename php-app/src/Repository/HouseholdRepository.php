<?php

declare(strict_types=1);

namespace HouseholdTracker\Repository;

use HouseholdTracker\Database\Connection;

final class HouseholdRepository
{
    public function create(string $name, int $createdByUserId): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'INSERT INTO households (name, created_by_user_id) VALUES (:name, :created_by_user_id)'
        );
        $stmt->execute(['name' => $name, 'created_by_user_id' => $createdByUserId]);

        return $this->findById((int) $pdo->lastInsertId());
    }

    public function updateName(int $id, string $name): void
    {
        $stmt = Connection::get()->prepare('UPDATE households SET name = :name WHERE id = :id');
        $stmt->execute(['name' => $name, 'id' => $id]);
    }

    public function findById(int $id): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM households WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $household = $stmt->fetch();

        return $household === false ? null : $household;
    }
}

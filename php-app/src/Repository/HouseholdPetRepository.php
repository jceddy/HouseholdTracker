<?php

declare(strict_types=1);

namespace HouseholdTracker\Repository;

use HouseholdTracker\Database\Connection;

final class HouseholdPetRepository
{
    public function create(
        int $householdId,
        int $createdByUserId,
        string $name,
        ?string $species,
        ?string $breed,
        ?string $birthday,
        ?string $notes
    ): array {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'INSERT INTO household_pets (household_id, name, species, breed, birthday, notes, created_by_user_id)
             VALUES (:household_id, :name, :species, :breed, :birthday, :notes, :created_by_user_id)'
        );
        $stmt->execute([
            'household_id' => $householdId,
            'name' => $name,
            'species' => $species,
            'breed' => $breed,
            'birthday' => $birthday,
            'notes' => $notes,
            'created_by_user_id' => $createdByUserId,
        ]);

        return $this->findById((int) $pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM household_pets WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function listForHousehold(int $householdId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM household_pets WHERE household_id = :household_id ORDER BY name ASC'
        );
        $stmt->execute(['household_id' => $householdId]);

        return $stmt->fetchAll();
    }

    public function update(
        int $id,
        string $name,
        ?string $species,
        ?string $breed,
        ?string $birthday,
        ?string $notes
    ): void {
        $stmt = Connection::get()->prepare(
            'UPDATE household_pets
             SET name = :name, species = :species, breed = :breed, birthday = :birthday, notes = :notes
             WHERE id = :id'
        );
        $stmt->execute([
            'name' => $name,
            'species' => $species,
            'breed' => $breed,
            'birthday' => $birthday,
            'notes' => $notes,
            'id' => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = Connection::get()->prepare('DELETE FROM household_pets WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}

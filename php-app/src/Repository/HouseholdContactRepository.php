<?php

declare(strict_types=1);

namespace HouseholdTracker\Repository;

use HouseholdTracker\Database\Connection;

final class HouseholdContactRepository
{
    public function create(
        int $householdId,
        int $createdByUserId,
        string $name,
        ?string $category,
        ?string $phone,
        ?string $email,
        ?string $address,
        ?string $notes
    ): array {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'INSERT INTO household_contacts (household_id, name, category, phone, email, address, notes, created_by_user_id)
             VALUES (:household_id, :name, :category, :phone, :email, :address, :notes, :created_by_user_id)'
        );
        $stmt->execute([
            'household_id' => $householdId,
            'name' => $name,
            'category' => $category,
            'phone' => $phone,
            'email' => $email,
            'address' => $address,
            'notes' => $notes,
            'created_by_user_id' => $createdByUserId,
        ]);

        return $this->findById((int) $pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM household_contacts WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function listForHousehold(int $householdId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM household_contacts WHERE household_id = :household_id ORDER BY name ASC'
        );
        $stmt->execute(['household_id' => $householdId]);

        return $stmt->fetchAll();
    }

    public function update(
        int $id,
        string $name,
        ?string $category,
        ?string $phone,
        ?string $email,
        ?string $address,
        ?string $notes
    ): void {
        $stmt = Connection::get()->prepare(
            'UPDATE household_contacts
             SET name = :name, category = :category, phone = :phone, email = :email, address = :address, notes = :notes
             WHERE id = :id'
        );
        $stmt->execute([
            'name' => $name,
            'category' => $category,
            'phone' => $phone,
            'email' => $email,
            'address' => $address,
            'notes' => $notes,
            'id' => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = Connection::get()->prepare('DELETE FROM household_contacts WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}

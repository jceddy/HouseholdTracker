<?php

declare(strict_types=1);

namespace HouseholdTracker\Repository;

use HouseholdTracker\Database\Connection;

final class HouseholdInventoryRepository
{
    public function create(
        int $householdId,
        int $createdByUserId,
        string $name,
        ?string $category,
        ?string $purchaseDate,
        ?string $purchasePrice,
        ?string $warrantyExpiresAt,
        ?string $serialNumber,
        ?string $location,
        ?string $notes,
        ?int $serviceContactId
    ): array {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'INSERT INTO household_inventory_items
                (household_id, name, category, purchase_date, purchase_price, warranty_expires_at, serial_number, location, notes, service_contact_id, created_by_user_id)
             VALUES
                (:household_id, :name, :category, :purchase_date, :purchase_price, :warranty_expires_at, :serial_number, :location, :notes, :service_contact_id, :created_by_user_id)'
        );
        $stmt->execute([
            'household_id' => $householdId,
            'name' => $name,
            'category' => $category,
            'purchase_date' => $purchaseDate,
            'purchase_price' => $purchasePrice,
            'warranty_expires_at' => $warrantyExpiresAt,
            'serial_number' => $serialNumber,
            'location' => $location,
            'notes' => $notes,
            'service_contact_id' => $serviceContactId,
            'created_by_user_id' => $createdByUserId,
        ]);

        return $this->findById((int) $pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM household_inventory_items WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function listForHousehold(int $householdId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM household_inventory_items WHERE household_id = :household_id ORDER BY name ASC'
        );
        $stmt->execute(['household_id' => $householdId]);

        return $stmt->fetchAll();
    }

    public function update(
        int $id,
        string $name,
        ?string $category,
        ?string $purchaseDate,
        ?string $purchasePrice,
        ?string $warrantyExpiresAt,
        ?string $serialNumber,
        ?string $location,
        ?string $notes,
        ?int $serviceContactId
    ): void {
        $stmt = Connection::get()->prepare(
            'UPDATE household_inventory_items
             SET name = :name, category = :category, purchase_date = :purchase_date, purchase_price = :purchase_price,
                 warranty_expires_at = :warranty_expires_at, serial_number = :serial_number, location = :location,
                 notes = :notes, service_contact_id = :service_contact_id
             WHERE id = :id'
        );
        $stmt->execute([
            'name' => $name,
            'category' => $category,
            'purchase_date' => $purchaseDate,
            'purchase_price' => $purchasePrice,
            'warranty_expires_at' => $warrantyExpiresAt,
            'serial_number' => $serialNumber,
            'location' => $location,
            'notes' => $notes,
            'service_contact_id' => $serviceContactId,
            'id' => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = Connection::get()->prepare('DELETE FROM household_inventory_items WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}

<?php

declare(strict_types=1);

namespace HouseholdTracker\Repository;

use HouseholdTracker\Database\Connection;

final class HouseholdMemberRepository
{
    public function add(int $householdId, int $userId, string $role): void
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO household_members (household_id, user_id, role) VALUES (:household_id, :user_id, :role)'
        );
        $stmt->execute(['household_id' => $householdId, 'user_id' => $userId, 'role' => $role]);
    }

    public function remove(int $householdId, int $userId): void
    {
        $stmt = Connection::get()->prepare(
            'DELETE FROM household_members WHERE household_id = :household_id AND user_id = :user_id'
        );
        $stmt->execute(['household_id' => $householdId, 'user_id' => $userId]);
    }

    public function find(int $householdId, int $userId): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM household_members WHERE household_id = :household_id AND user_id = :user_id'
        );
        $stmt->execute(['household_id' => $householdId, 'user_id' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * listHouseholdsForUser(userId) - every household this user belongs to, with
     * their own role in each.
     */
    public function listHouseholdsForUser(int $userId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT households.id, households.name, households.created_at, household_members.role
             FROM household_members
             INNER JOIN households ON households.id = household_members.household_id
             WHERE household_members.user_id = :user_id
             ORDER BY households.name'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    /**
     * listForHousehold(householdId) - every member of a household, with their
     * username/email and role.
     */
    public function listForHousehold(int $householdId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT users.id AS user_id, users.username, users.email, household_members.role, household_members.joined_at
             FROM household_members
             INNER JOIN users ON users.id = household_members.user_id
             WHERE household_members.household_id = :household_id
             ORDER BY household_members.role DESC, users.username'
        );
        $stmt->execute(['household_id' => $householdId]);

        return $stmt->fetchAll();
    }
}

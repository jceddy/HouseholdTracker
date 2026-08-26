<?php

declare(strict_types=1);

namespace HouseholdTracker\Repository;

use HouseholdTracker\Database\Connection;

final class HouseholdInviteRepository
{
    public function create(int $householdId, int $invitedUserId, int $invitedByUserId): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'INSERT INTO household_invites (household_id, invited_user_id, invited_by_user_id)
             VALUES (:household_id, :invited_user_id, :invited_by_user_id)'
        );
        $stmt->execute([
            'household_id' => $householdId,
            'invited_user_id' => $invitedUserId,
            'invited_by_user_id' => $invitedByUserId,
        ]);

        return $this->findById((int) $pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM household_invites WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findPending(int $householdId, int $invitedUserId): ?array
    {
        $stmt = Connection::get()->prepare(
            "SELECT * FROM household_invites
             WHERE household_id = :household_id AND invited_user_id = :invited_user_id AND status = 'pending'"
        );
        $stmt->execute(['household_id' => $householdId, 'invited_user_id' => $invitedUserId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * listPendingForUser(userId) - every pending invite sent to this user, with
     * the household name and inviter's username.
     */
    public function listPendingForUser(int $userId): array
    {
        $stmt = Connection::get()->prepare(
            "SELECT household_invites.id, household_invites.household_id, households.name AS household_name,
                    household_invites.invited_by_user_id, inviters.username AS invited_by_username,
                    household_invites.created_at
             FROM household_invites
             INNER JOIN households ON households.id = household_invites.household_id
             INNER JOIN users AS inviters ON inviters.id = household_invites.invited_by_user_id
             WHERE household_invites.invited_user_id = :user_id AND household_invites.status = 'pending'
             ORDER BY household_invites.created_at DESC"
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public function markResponded(int $id, string $status): void
    {
        $stmt = Connection::get()->prepare(
            'UPDATE household_invites SET status = :status, responded_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['status' => $status, 'id' => $id]);
    }
}

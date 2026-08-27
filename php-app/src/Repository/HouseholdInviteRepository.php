<?php

declare(strict_types=1);

namespace HouseholdTracker\Repository;

use HouseholdTracker\Database\Connection;

final class HouseholdInviteRepository
{
    public function createForUser(int $householdId, int $invitedUserId, int $invitedByUserId): array
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

    public function createForEmail(int $householdId, string $invitedEmail, int $invitedByUserId): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'INSERT INTO household_invites (household_id, invited_email, invited_by_user_id)
             VALUES (:household_id, :invited_email, :invited_by_user_id)'
        );
        $stmt->execute([
            'household_id' => $householdId,
            'invited_email' => $invitedEmail,
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

    public function findPendingForUser(int $householdId, int $invitedUserId): ?array
    {
        $stmt = Connection::get()->prepare(
            "SELECT * FROM household_invites
             WHERE household_id = :household_id AND invited_user_id = :invited_user_id AND status = 'pending'"
        );
        $stmt->execute(['household_id' => $householdId, 'invited_user_id' => $invitedUserId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findPendingForEmail(int $householdId, string $invitedEmail): ?array
    {
        $stmt = Connection::get()->prepare(
            "SELECT * FROM household_invites
             WHERE household_id = :household_id AND invited_email = :invited_email AND status = 'pending'"
        );
        $stmt->execute(['household_id' => $householdId, 'invited_email' => $invitedEmail]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * findAllPendingForEmail(email) - every pending invite (across every
     * household) addressed to this email, matched case-insensitively --
     * used to link them to a real user_id the moment that email is verified
     * during registration (see HouseholdService::linkPendingInvitesForEmail()).
     *
     * @return array<int, array>
     */
    public function findAllPendingForEmail(string $email): array
    {
        $stmt = Connection::get()->prepare(
            "SELECT * FROM household_invites
             WHERE invited_email IS NOT NULL AND LOWER(invited_email) = LOWER(:email) AND status = 'pending'"
        );
        $stmt->execute(['email' => $email]);

        return $stmt->fetchAll();
    }

    /**
     * linkToUser(...) - converts a pending email-only invite into an ordinary
     * existing-user invite once that email is verified, so it surfaces
     * through the normal listPendingForUser()/markResponded() flow with no
     * separate acceptance path of its own.
     */
    public function linkToUser(int $inviteId, int $userId): void
    {
        $stmt = Connection::get()->prepare(
            'UPDATE household_invites SET invited_user_id = :user_id, invited_email = NULL WHERE id = :id'
        );
        $stmt->execute(['user_id' => $userId, 'id' => $inviteId]);
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

    /**
     * delete(...) - rolls back an email invite whose invitation email failed
     * to send, mirroring AuthService::cancelRegistration()'s own
     * rollback-on-failed-email pattern.
     */
    public function delete(int $id): void
    {
        $stmt = Connection::get()->prepare('DELETE FROM household_invites WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}

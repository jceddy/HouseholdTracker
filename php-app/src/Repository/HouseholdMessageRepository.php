<?php

declare(strict_types=1);

namespace HouseholdTracker\Repository;

use HouseholdTracker\Database\Connection;

final class HouseholdMessageRepository
{
    public function create(int $householdId, int $senderUserId, string $body): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'INSERT INTO household_messages (household_id, sender_user_id, body)
             VALUES (:household_id, :sender_user_id, :body)'
        );
        $stmt->execute([
            'household_id' => $householdId,
            'sender_user_id' => $senderUserId,
            'body' => $body,
        ]);

        return $this->findById((int) $pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM household_messages WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * listForHousehold(...) - with $sinceId, every message newer than it
     * (a poll's incremental fetch); without it, the most recent $limit
     * messages (the initial page load), oldest-first either way so the
     * frontend can just append. No further pagination for v1 -- see
     * migration 0041's own comment.
     */
    public function listForHousehold(int $householdId, ?int $sinceId, int $limit): array
    {
        if ($sinceId !== null) {
            $stmt = Connection::get()->prepare(
                'SELECT * FROM household_messages
                 WHERE household_id = :household_id AND id > :since_id
                 ORDER BY id ASC'
            );
            $stmt->execute(['household_id' => $householdId, 'since_id' => $sinceId]);

            return $stmt->fetchAll();
        }

        $stmt = Connection::get()->prepare(
            'SELECT * FROM (
                 SELECT * FROM household_messages
                 WHERE household_id = :household_id
                 ORDER BY id DESC
                 LIMIT :limit
             ) recent
             ORDER BY id ASC'
        );
        $stmt->bindValue('household_id', $householdId, \PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}

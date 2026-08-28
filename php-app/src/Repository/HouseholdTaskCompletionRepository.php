<?php

declare(strict_types=1);

namespace HouseholdTracker\Repository;

use HouseholdTracker\Database\Connection;

final class HouseholdTaskCompletionRepository
{
    public function create(int $taskId, int $completedByUserId, ?string $notes): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'INSERT INTO household_task_completions (task_id, completed_by_user_id, notes)
             VALUES (:task_id, :completed_by_user_id, :notes)'
        );
        $stmt->execute([
            'task_id' => $taskId,
            'completed_by_user_id' => $completedByUserId,
            'notes' => $notes,
        ]);

        $findStmt = Connection::get()->prepare('SELECT * FROM household_task_completions WHERE id = :id');
        $findStmt->execute(['id' => (int) $pdo->lastInsertId()]);

        return $findStmt->fetch();
    }
}

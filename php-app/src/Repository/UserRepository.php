<?php

declare(strict_types=1);

namespace HouseholdTracker\Repository;

use HouseholdTracker\Database\Connection;

final class UserRepository
{
    public function create(string $username, string $email, string $passwordHash): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, email, password_hash) VALUES (:username, :email, :password_hash)'
        );
        $stmt->execute([
            'username' => $username,
            'email' => $email,
            'password_hash' => $passwordHash,
        ]);

        return $this->findById((int) $pdo->lastInsertId());
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM users WHERE username = :username');
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        return $user === false ? null : $user;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        return $user === false ? null : $user;
    }

    public function findById(int $id): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();

        return $user === false ? null : $user;
    }

    public function markEmailVerified(int $id): void
    {
        $stmt = Connection::get()->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function updateUsername(int $id, string $username): void
    {
        $stmt = Connection::get()->prepare('UPDATE users SET username = :username WHERE id = :id');
        $stmt->execute(['username' => $username, 'id' => $id]);
    }

    public function setPendingEmail(int $id, string $pendingEmail): void
    {
        $stmt = Connection::get()->prepare('UPDATE users SET pending_email = :pending_email WHERE id = :id');
        $stmt->execute(['pending_email' => $pendingEmail, 'id' => $id]);
    }

    /**
     * applyPendingEmail(...) - the verification side of setPendingEmail():
     * promotes pending_email to email, clears pending_email, and marks the
     * (now current) address verified, all in one statement so there's no
     * window where email_verified_at is stale relative to email.
     */
    public function applyPendingEmail(int $id, string $newEmail): void
    {
        $stmt = Connection::get()->prepare(
            'UPDATE users SET email = :email, pending_email = NULL, email_verified_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['email' => $newEmail, 'id' => $id]);
    }

    public function updatePasswordHash(int $id, string $passwordHash): void
    {
        $stmt = Connection::get()->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
        $stmt->execute(['password_hash' => $passwordHash, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = Connection::get()->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}

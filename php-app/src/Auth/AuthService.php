<?php

declare(strict_types=1);

namespace HouseholdTracker\Auth;

use DateTimeImmutable;
use HouseholdTracker\Repository\EmailVerificationRepository;
use HouseholdTracker\Repository\PasswordResetRepository;
use HouseholdTracker\Repository\SessionRepository;
use HouseholdTracker\Repository\UserRepository;
use PDOException;

final class AuthService
{
    public const COOKIE_NAME = 'session_token';
    public const SESSION_TTL_DAYS = 30;
    public const EMAIL_VERIFICATION_TTL_HOURS = 24;
    public const RESEND_MIN_INTERVAL_SECONDS = 60;
    public const PASSWORD_RESET_TTL_HOURS = 1;

    public function __construct(
        private readonly UserRepository $users,
        private readonly SessionRepository $sessions,
        private readonly EmailVerificationRepository $emailVerifications,
        private readonly PasswordResetRepository $passwordResets,
        private readonly int $resendMinIntervalSeconds = self::RESEND_MIN_INTERVAL_SECONDS,
    ) {
    }

    /**
     * @return array{user: array, verificationToken: string}
     */
    public function register(string $username, string $email, string $password): array
    {
        $username = trim($username);
        $email = trim($email);

        if (!preg_match('/^[A-Za-z0-9_-]{3,32}$/', $username)) {
            throw new \InvalidArgumentException(
                'Username must be 3-32 characters (letters, numbers, "_", "-").'
            );
        }

        if (strlen($password) < 8 || strlen($password) > 72) {
            throw new \InvalidArgumentException('Password must be between 8 and 72 characters.');
        }

        if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('A valid email address is required.');
        }

        if ($this->users->findByUsername($username) !== null) {
            throw new DuplicateUsernameException("Username \"{$username}\" is already taken.");
        }

        if ($this->users->findByEmail($email) !== null) {
            throw new DuplicateEmailException("An account with email \"{$email}\" already exists.");
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);

        try {
            $user = $this->users->create($username, $email, $hash);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new DuplicateUsernameException("Username \"{$username}\" or email \"{$email}\" is already taken.");
            }
            throw $e;
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = new DateTimeImmutable('+' . self::EMAIL_VERIFICATION_TTL_HOURS . ' hours');
        $this->emailVerifications->create((int) $user['id'], hash('sha256', $token), $expiresAt);

        return ['user' => $user, 'verificationToken' => $token];
    }

    /**
     * Rolls back a registration whose verification email failed to send, so
     * the user isn't left with an unusable, unverifiable account.
     */
    public function cancelRegistration(int $userId): void
    {
        $this->users->delete($userId);
    }

    /**
     * Issues a fresh verification token for an unverified account, invalidating
     * any prior ones. Returns null when there's nothing to do (unknown email,
     * already verified, or a resend was requested too recently) so the caller
     * can respond identically in every case and avoid leaking account state.
     *
     * @return array{user: array, verificationToken: string}|null
     */
    public function resendVerificationEmail(string $email): ?array
    {
        $user = $this->users->findByEmail(trim($email));

        if ($user === null || $user['email_verified_at'] !== null) {
            return null;
        }

        $userId = (int) $user['id'];
        $lastSentAt = $this->emailVerifications->mostRecentCreatedAtForUser($userId);

        if ($lastSentAt !== null && (time() - $lastSentAt->getTimestamp()) < $this->resendMinIntervalSeconds) {
            return null;
        }

        $this->emailVerifications->deleteAllForUser($userId);

        $token = bin2hex(random_bytes(32));
        $expiresAt = new DateTimeImmutable('+' . self::EMAIL_VERIFICATION_TTL_HOURS . ' hours');
        $this->emailVerifications->create($userId, hash('sha256', $token), $expiresAt);

        return ['user' => $user, 'verificationToken' => $token];
    }

    /**
     * Marks the account verified -- or, if this token was issued for an
     * in-progress email change (see updateProfile()), promotes
     * pending_email to the account's real email instead. Either way is the
     * same link a user clicks from an email, so one route/method handles
     * both; which one happened is just whether pending_email was set.
     */
    public function verifyEmail(string $token): array
    {
        $verification = $this->emailVerifications->findValidByTokenHash(hash('sha256', $token));

        if ($verification === null) {
            throw new InvalidVerificationTokenException('This verification link is invalid or has expired.');
        }

        $userId = (int) $verification['user_id'];
        $user = $this->users->findById($userId);

        if ($user['pending_email'] !== null) {
            $this->users->applyPendingEmail($userId, $user['pending_email']);
        } else {
            $this->users->markEmailVerified($userId);
        }

        $this->emailVerifications->deleteAllForUser($userId);

        return $this->users->findById($userId);
    }

    /**
     * Issues a password reset token for any known email, verified or not,
     * invalidating any prior ones. Returns null when there's nothing to do
     * (unknown email, or a request was made too recently) so the caller can
     * respond identically in every case and avoid leaking account state.
     *
     * @return array{user: array, resetToken: string}|null
     */
    public function requestPasswordReset(string $email): ?array
    {
        $user = $this->users->findByEmail(trim($email));

        if ($user === null) {
            return null;
        }

        $userId = (int) $user['id'];
        $lastSentAt = $this->passwordResets->mostRecentCreatedAtForUser($userId);

        if ($lastSentAt !== null && (time() - $lastSentAt->getTimestamp()) < $this->resendMinIntervalSeconds) {
            return null;
        }

        $this->passwordResets->deleteAllForUser($userId);

        $token = bin2hex(random_bytes(32));
        $expiresAt = new DateTimeImmutable('+' . self::PASSWORD_RESET_TTL_HOURS . ' hours');
        $this->passwordResets->create($userId, hash('sha256', $token), $expiresAt);

        return ['user' => $user, 'resetToken' => $token];
    }

    /**
     * Consumes a password reset token, sets the new password, and logs the
     * user out everywhere by deleting all of their sessions -- a password
     * reset is also a signal that any existing session may be compromised.
     */
    public function resetPassword(string $token, string $newPassword): array
    {
        if (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
            throw new \InvalidArgumentException('Password must be between 8 and 72 characters.');
        }

        $userId = $this->passwordResets->consumeValid(hash('sha256', $token));

        if ($userId === null) {
            throw new InvalidPasswordResetTokenException('This password reset link is invalid or has expired.');
        }

        $this->users->updatePasswordHash($userId, password_hash($newPassword, PASSWORD_BCRYPT));
        $this->sessions->deleteAllForUser($userId);

        return $this->users->findById($userId);
    }

    /**
     * @return array{user: array, token: string, expiresAt: DateTimeImmutable}
     */
    public function login(string $username, string $password, ?string $ipAddress, ?string $userAgent): array
    {
        $user = $this->users->findByUsername($username);

        if ($user === null || !password_verify($password, $user['password_hash'])) {
            throw new InvalidCredentialsException('Invalid username or password.');
        }

        if ($user['email_verified_at'] === null) {
            throw new EmailNotVerifiedException('Please verify your email address before logging in.');
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = new DateTimeImmutable('+' . self::SESSION_TTL_DAYS . ' days');

        $this->sessions->create((int) $user['id'], hash('sha256', $token), $expiresAt, $ipAddress, $userAgent);

        return ['user' => $user, 'token' => $token, 'expiresAt' => $expiresAt];
    }

    public function logout(string $token): void
    {
        $this->sessions->deleteByTokenHash(hash('sha256', $token));
    }

    /**
     * updateProfile(...) - a username change applies immediately (no
     * re-verification needed, it's not a contact address); an email change
     * does not -- it's stashed in pending_email and only takes effect once
     * its verification link is clicked (see verifyEmail()), the same
     * email_verifications token flow registration already uses. Either
     * argument may be null to leave that field alone, and a value equal to
     * the user's current one is a no-op rather than an error (so re-
     * submitting an unchanged form field doesn't trip the uniqueness check
     * against the user's own existing row).
     *
     * @return array{user: array, verificationToken: ?string}
     */
    public function updateProfile(int $userId, ?string $newUsername, ?string $newEmail): array
    {
        $user = $this->users->findById($userId);

        if ($newUsername !== null) {
            $newUsername = trim($newUsername);
            if (!preg_match('/^[A-Za-z0-9_-]{3,32}$/', $newUsername)) {
                throw new \InvalidArgumentException(
                    'Username must be 3-32 characters (letters, numbers, "_", "-").'
                );
            }
            if ($newUsername !== $user['username']) {
                if ($this->users->findByUsername($newUsername) !== null) {
                    throw new DuplicateUsernameException("Username \"{$newUsername}\" is already taken.");
                }
                $this->users->updateUsername($userId, $newUsername);
            }
        }

        $verificationToken = null;

        if ($newEmail !== null) {
            $newEmail = trim($newEmail);
            if (strlen($newEmail) > 255 || filter_var($newEmail, FILTER_VALIDATE_EMAIL) === false) {
                throw new \InvalidArgumentException('A valid email address is required.');
            }
            if ($newEmail !== $user['email']) {
                if ($this->users->findByEmail($newEmail) !== null) {
                    throw new DuplicateEmailException("An account with email \"{$newEmail}\" already exists.");
                }

                $this->users->setPendingEmail($userId, $newEmail);
                $this->emailVerifications->deleteAllForUser($userId);

                $verificationToken = bin2hex(random_bytes(32));
                $expiresAt = new DateTimeImmutable('+' . self::EMAIL_VERIFICATION_TTL_HOURS . ' hours');
                $this->emailVerifications->create($userId, hash('sha256', $verificationToken), $expiresAt);
            }
        }

        return ['user' => $this->users->findById($userId), 'verificationToken' => $verificationToken];
    }

    /**
     * The logged-in counterpart to resetPassword() -- proves identity via
     * the current password instead of an emailed token. Same "any existing
     * session may be compromised" reasoning applies just as much to a
     * deliberate change as to a forced reset, so this logs out everywhere
     * too, current session included; the caller (index.php's route) clears
     * this request's own cookie and the frontend sends the user back to
     * the login page.
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): void
    {
        $user = $this->users->findById($userId);

        if (!password_verify($currentPassword, $user['password_hash'])) {
            throw new InvalidCurrentPasswordException('Current password is incorrect.');
        }

        if (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
            throw new \InvalidArgumentException('Password must be between 8 and 72 characters.');
        }

        $this->users->updatePasswordHash($userId, password_hash($newPassword, PASSWORD_BCRYPT));
        $this->sessions->deleteAllForUser($userId);
    }

    /**
     * A real, user-initiated deletion (distinct from cancelRegistration()'s
     * internal rollback path above) -- gated on the account's own password
     * as confirmation, same as changePassword(). No explicit session
     * cleanup needed: sessions.user_id is ON DELETE CASCADE (see
     * database/migrations/0001_baseline.sql), and every other table that
     * references users.id cascades or nulls out per its own migration --
     * see "Account deletion" in php-app/README.md for the full picture,
     * including a household's own fate when its creator deletes their
     * account.
     */
    public function deleteAccount(int $userId, string $password): void
    {
        $user = $this->users->findById($userId);

        if (!password_verify($password, $user['password_hash'])) {
            throw new InvalidCurrentPasswordException('Password is incorrect.');
        }

        $this->users->delete($userId);
    }

    /**
     * @return array{user: array{id: int, username: string, email: string}, expiresAt: DateTimeImmutable}|null
     */
    public function currentUser(string $token): ?array
    {
        $session = $this->sessions->findValidByTokenHash(hash('sha256', $token));

        if ($session === null) {
            return null;
        }

        $expiresAt = new DateTimeImmutable('+' . self::SESSION_TTL_DAYS . ' days');
        $this->sessions->touch((int) $session['id'], $expiresAt);

        return [
            'user' => [
                'id' => (int) $session['user_id'],
                'username' => $session['username'],
                'email' => $session['email'],
                'pending_email' => $session['pending_email'],
            ],
            'expiresAt' => $expiresAt,
        ];
    }
}

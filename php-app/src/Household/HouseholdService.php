<?php

declare(strict_types=1);

namespace HouseholdTracker\Household;

use HouseholdTracker\Repository\HouseholdInviteRepository;
use HouseholdTracker\Repository\HouseholdMemberRepository;
use HouseholdTracker\Repository\HouseholdRepository;
use HouseholdTracker\Repository\UserRepository;

/**
 * Household creation, membership, and the invite flow (issues #5, #33). A
 * user may belong to any number of households (household_members has no
 * uniqueness constraint on user_id alone). Invites target either an existing
 * registered user (looked up by username then email, mirroring
 * AuthService::register()'s own validation order) or, if neither matches and
 * the input is a valid email address, an unregistered one -- that invite
 * doubles as a registration link (see inviteMember()/
 * linkPendingInvitesForEmail()).
 */
final class HouseholdService
{
    public function __construct(
        private readonly HouseholdRepository $households,
        private readonly HouseholdMemberRepository $members,
        private readonly HouseholdInviteRepository $invites,
        private readonly UserRepository $users,
    ) {
    }

    public function createHousehold(int $userId, string $name): array
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 100) {
            throw new \InvalidArgumentException('Household name must be 1-100 characters.');
        }

        $household = $this->households->create($name, $userId);
        $this->members->add((int) $household['id'], $userId, 'owner');

        return $household;
    }

    public function listHouseholdsForUser(int $userId): array
    {
        return $this->members->listHouseholdsForUser($userId);
    }

    public function listMembers(int $callerId, int $householdId): array
    {
        $this->requireMember($householdId, $callerId);

        return $this->members->listForHousehold($householdId);
    }

    /**
     * inviteMember(...) - invites an existing user by username or email. If
     * neither matches any account, but the input is itself a valid email
     * address, invites that address instead (issue #33): a pending invite
     * with invited_email set and no invited_user_id yet, which
     * linkPendingInvitesForEmail() converts to an ordinary existing-user
     * invite the moment that email is verified during registration -- no
     * separate acceptance path, it just becomes a normal pending invite at
     * that point.
     *
     * @return array{type: 'existing_user', invite: array, invitedUser: array, household: array}
     *     |array{type: 'new_email', invite: array, invitedEmail: string, household: array}
     */
    public function inviteMember(int $householdId, int $inviterUserId, string $usernameOrEmail): array
    {
        $this->requireMember($householdId, $inviterUserId);
        $household = $this->households->findById($householdId);

        $usernameOrEmail = trim($usernameOrEmail);
        $target = $this->users->findByUsername($usernameOrEmail) ?? $this->users->findByEmail($usernameOrEmail);

        if ($target === null) {
            if (filter_var($usernameOrEmail, FILTER_VALIDATE_EMAIL) === false) {
                throw new UserNotFoundException("No account found for \"{$usernameOrEmail}\".");
            }

            if ($this->invites->findPendingForEmail($householdId, $usernameOrEmail) !== null) {
                throw new AlreadyMemberException("{$usernameOrEmail} already has a pending invite to this household.");
            }

            $invite = $this->invites->createForEmail($householdId, $usernameOrEmail, $inviterUserId);

            return ['type' => 'new_email', 'invite' => $invite, 'invitedEmail' => $usernameOrEmail, 'household' => $household];
        }

        $targetUserId = (int) $target['id'];
        if ($targetUserId === $inviterUserId) {
            throw new CannotInviteSelfException('You cannot invite yourself.');
        }

        if ($this->members->find($householdId, $targetUserId) !== null) {
            throw new AlreadyMemberException("{$target['username']} is already a member of this household.");
        }

        if ($this->invites->findPendingForUser($householdId, $targetUserId) !== null) {
            throw new AlreadyMemberException("{$target['username']} already has a pending invite to this household.");
        }

        $invite = $this->invites->createForUser($householdId, $targetUserId, $inviterUserId);

        return ['type' => 'existing_user', 'invite' => $invite, 'invitedUser' => $target, 'household' => $household];
    }

    /**
     * cancelInvite(...) - rolls back an email invite whose invitation email
     * failed to send, mirroring AuthService::cancelRegistration()'s own
     * rollback-on-failed-email pattern.
     */
    public function cancelInvite(int $inviteId): void
    {
        $this->invites->delete($inviteId);
    }

    /**
     * linkPendingInvitesForEmail(...) - called once a NEW account's email is
     * verified (see the /verify-email route, right after
     * AuthService::verifyEmail() succeeds): converts every pending
     * email-only invite addressed to it into an ordinary existing-user
     * invite, so it shows up through the normal
     * listInvitesForUser()/respondToInvite() flow like any other invite.
     * Deliberately does not auto-join the household -- the person still has
     * to accept it, the same as an invite to an already-registered user.
     */
    public function linkPendingInvitesForEmail(int $userId, string $email): void
    {
        foreach ($this->invites->findAllPendingForEmail($email) as $invite) {
            $this->invites->linkToUser((int) $invite['id'], $userId);
        }
    }

    public function listInvitesForUser(int $userId): array
    {
        return $this->invites->listPendingForUser($userId);
    }

    public function respondToInvite(int $userId, int $inviteId, string $action): void
    {
        if (!in_array($action, ['accept', 'decline'], true)) {
            throw new \InvalidArgumentException('action must be "accept" or "decline".');
        }

        $invite = $this->invites->findById($inviteId);
        if ($invite === null || (int) $invite['invited_user_id'] !== $userId || $invite['status'] !== 'pending') {
            throw new InviteNotFoundException('No pending invite found.');
        }

        if ($action === 'accept') {
            $this->members->add((int) $invite['household_id'], $userId, 'member');
        }

        $this->invites->markResponded($inviteId, $action === 'accept' ? 'accepted' : 'declined');
    }

    /**
     * removeMember(...) - a member may remove themselves (leave); removing
     * someone else requires the caller to be the household's owner. v1 has no
     * ownership-transfer story (see issue #17), so an owner can also leave
     * their own household unchallenged, same as any member.
     */
    public function removeMember(int $callerId, int $householdId, int $targetUserId): void
    {
        $callerMembership = $this->members->find($householdId, $callerId);
        if ($callerMembership === null) {
            throw new NotAHouseholdMemberException('You are not a member of this household.');
        }

        if ($callerId !== $targetUserId && $callerMembership['role'] !== 'owner') {
            throw new NotAuthorizedToRemoveMemberException('Only the household owner can remove other members.');
        }

        if ($this->members->find($householdId, $targetUserId) === null) {
            throw new NotAHouseholdMemberException('That user is not a member of this household.');
        }

        $this->members->remove($householdId, $targetUserId);
    }

    private function requireMember(int $householdId, int $userId): void
    {
        if ($this->members->find($householdId, $userId) === null) {
            throw new NotAHouseholdMemberException('You are not a member of this household.');
        }
    }
}

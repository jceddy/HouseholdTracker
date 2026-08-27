<?php

declare(strict_types=1);

namespace HouseholdTracker\Household;

use HouseholdTracker\Repository\HouseholdInviteRepository;
use HouseholdTracker\Repository\HouseholdMemberRepository;
use HouseholdTracker\Repository\HouseholdNoteRepository;
use HouseholdTracker\Repository\HouseholdPetRepository;
use HouseholdTracker\Repository\HouseholdRepository;
use HouseholdTracker\Repository\UserRepository;

/**
 * Household creation, membership, the invite flow (issues #5, #33), and
 * household-scoped settings/notes/pets (issue #7). A user may belong to any
 * number of households (household_members has no uniqueness constraint on
 * user_id alone). Invites target either an existing registered user (looked
 * up by username then email, mirroring AuthService::register()'s own
 * validation order) or, if neither matches and the input is a valid email
 * address, an unregistered one -- that invite doubles as a registration link
 * (see inviteMember()/linkPendingInvitesForEmail()).
 */
final class HouseholdService
{
    public function __construct(
        private readonly HouseholdRepository $households,
        private readonly HouseholdMemberRepository $members,
        private readonly HouseholdInviteRepository $invites,
        private readonly UserRepository $users,
        private readonly HouseholdNoteRepository $notes,
        private readonly HouseholdPetRepository $pets,
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

    /**
     * updateSettings(...) - v1 of "household settings" (issue #7) is just the
     * household's own name, so this updates the households.name column
     * directly rather than a separate key/value settings table. Any member
     * may update it, not just the owner.
     */
    public function updateSettings(int $callerId, int $householdId, string $name): array
    {
        $this->requireMember($householdId, $callerId);

        $name = trim($name);
        if ($name === '' || strlen($name) > 100) {
            throw new \InvalidArgumentException('Household name must be 1-100 characters.');
        }

        $this->households->updateName($householdId, $name);

        return $this->households->findById($householdId);
    }

    public function listNotes(int $callerId, int $householdId): array
    {
        $this->requireMember($householdId, $callerId);

        return $this->notes->listVisibleTo($householdId, $callerId);
    }

    public function createNote(int $callerId, int $householdId, string $visibility, string $body): array
    {
        $this->requireMember($householdId, $callerId);
        [$visibility, $body] = $this->validateNoteInput($visibility, $body);

        return $this->notes->create($householdId, $callerId, $visibility, $body);
    }

    /**
     * updateNote(...)/deleteNote(...) - a note, public or private, may only
     * be edited or deleted by its own author (open question in issue #7,
     * resolved the same way for both visibility tiers rather than letting
     * any member edit a public one).
     */
    public function updateNote(int $callerId, int $noteId, string $visibility, string $body): array
    {
        $this->requireOwnNote($callerId, $noteId);
        [$visibility, $body] = $this->validateNoteInput($visibility, $body);
        $this->notes->update($noteId, $visibility, $body);

        return $this->notes->findById($noteId);
    }

    public function deleteNote(int $callerId, int $noteId): void
    {
        $this->requireOwnNote($callerId, $noteId);
        $this->notes->delete($noteId);
    }

    private function requireOwnNote(int $callerId, int $noteId): array
    {
        $note = $this->notes->findById($noteId);
        if ($note === null) {
            throw new NoteNotFoundException('Note not found.');
        }

        if ((int) $note['author_user_id'] !== $callerId) {
            throw new NotAuthorizedToModifyNoteException("Only a note's own author can edit or delete it.");
        }

        return $note;
    }

    private function validateNoteInput(string $visibility, string $body): array
    {
        if (!in_array($visibility, ['private', 'public'], true)) {
            throw new \InvalidArgumentException('visibility must be "private" or "public".');
        }

        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 20000) {
            throw new \InvalidArgumentException('Note body must be 1-20,000 characters.');
        }

        return [$visibility, $body];
    }

    public function listPets(int $callerId, int $householdId): array
    {
        $this->requireMember($householdId, $callerId);

        return $this->pets->listForHousehold($householdId);
    }

    /**
     * createPet(...)/updatePet(...)/deletePet(...) - pets have no privacy
     * tiers, unlike notes: every household member sees the full list, and
     * any member may add, edit, or remove a pet (a shared household
     * resource, not a per-user one). vet_contact_id is deliberately absent
     * for now -- issue #16 (household contacts) hasn't shipped yet; see the
     * migration's own comment.
     */
    public function createPet(
        int $callerId,
        int $householdId,
        string $name,
        ?string $species,
        ?string $breed,
        ?string $birthday,
        ?string $notes
    ): array {
        $this->requireMember($householdId, $callerId);
        [$name, $species, $breed, $birthday, $notes] = $this->validatePetInput($name, $species, $breed, $birthday, $notes);

        return $this->pets->create($householdId, $callerId, $name, $species, $breed, $birthday, $notes);
    }

    public function updatePet(
        int $callerId,
        int $petId,
        string $name,
        ?string $species,
        ?string $breed,
        ?string $birthday,
        ?string $notes
    ): array {
        $pet = $this->requireMemberForPet($callerId, $petId);
        [$name, $species, $breed, $birthday, $notes] = $this->validatePetInput($name, $species, $breed, $birthday, $notes);
        $this->pets->update((int) $pet['id'], $name, $species, $breed, $birthday, $notes);

        return $this->pets->findById((int) $pet['id']);
    }

    public function deletePet(int $callerId, int $petId): void
    {
        $pet = $this->requireMemberForPet($callerId, $petId);
        $this->pets->delete((int) $pet['id']);
    }

    private function requireMemberForPet(int $callerId, int $petId): array
    {
        $pet = $this->pets->findById($petId);
        if ($pet === null) {
            throw new PetNotFoundException('Pet not found.');
        }

        $this->requireMember((int) $pet['household_id'], $callerId);

        return $pet;
    }

    private function validatePetInput(
        string $name,
        ?string $species,
        ?string $breed,
        ?string $birthday,
        ?string $notes
    ): array {
        $name = trim($name);
        if ($name === '' || strlen($name) > 100) {
            throw new \InvalidArgumentException('Pet name must be 1-100 characters.');
        }

        $species = $species !== null ? trim($species) : null;
        $species = $species === '' ? null : $species;

        $breed = $breed !== null ? trim($breed) : null;
        $breed = $breed === '' ? null : $breed;

        $birthday = $birthday !== null ? trim($birthday) : null;
        if ($birthday === '') {
            $birthday = null;
        }
        if ($birthday !== null) {
            $date = \DateTime::createFromFormat('Y-m-d', $birthday);
            if ($date === false || $date->format('Y-m-d') !== $birthday) {
                throw new \InvalidArgumentException('birthday must be in YYYY-MM-DD format.');
            }
        }

        $notes = $notes !== null ? trim($notes) : null;
        $notes = $notes === '' ? null : $notes;
        if ($notes !== null && strlen($notes) > 2000) {
            throw new \InvalidArgumentException('Pet notes must be 2000 characters or fewer.');
        }

        return [$name, $species, $breed, $birthday, $notes];
    }

    private function requireMember(int $householdId, int $userId): void
    {
        if ($this->members->find($householdId, $userId) === null) {
            throw new NotAHouseholdMemberException('You are not a member of this household.');
        }
    }
}

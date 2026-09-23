<?php

declare(strict_types=1);

namespace HouseholdTracker\Household;

use HouseholdTracker\Repository\HouseholdCalendarEventRepository;
use HouseholdTracker\Repository\HouseholdInviteRepository;
use HouseholdTracker\Repository\HouseholdMemberRepository;
use HouseholdTracker\Repository\HouseholdNoteRepository;
use HouseholdTracker\Repository\HouseholdPetRepository;
use HouseholdTracker\Repository\HouseholdRepository;
use HouseholdTracker\Repository\HouseholdShoppingItemRepository;
use HouseholdTracker\Repository\HouseholdStapleItemRepository;
use HouseholdTracker\Repository\UserRepository;

/**
 * Household creation, membership, the invite flow (issues #5, #33), and
 * household-scoped settings/notes/pets/shopping list/staples/calendar
 * (issues #7, #24, #66, #13). A user may belong to any number of households
 * (household_members has no uniqueness constraint on user_id alone).
 * Invites target either an existing registered user (looked up by username
 * then email, mirroring AuthService::register()'s own validation order) or,
 * if neither matches and the input is a valid email address, an
 * unregistered one -- that invite doubles as a registration link (see
 * inviteMember()/linkPendingInvitesForEmail()).
 *
 * Permissions (issue #17): requireMember() gates "is the caller a member at
 * all" (every route in this class needs at least that); requireOwner()
 * gates the two actions reserved for the household's owner -- removing
 * another member and deleting the household outright. Every other action
 * (inviting, editing settings, and every tracker's own create/edit/delete)
 * is deliberately "any member" -- see "Household roles and permissions" in
 * php-app/README.md for the full matrix and the reasoning behind it.
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
        private readonly HouseholdShoppingItemRepository $shoppingItems,
        private readonly HouseholdStapleItemRepository $staples,
        private readonly HouseholdCalendarEventRepository $calendarEvents,
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
     * removeMember(...) - a member may remove themselves (leave) freely;
     * removing someone else requires the caller to be the household's
     * owner (issue #17). v1 has no ownership-transfer story, so an owner
     * can also leave their own household unchallenged, same as any member
     * -- there's deliberately no "last owner" special case blocking it.
     */
    public function removeMember(int $callerId, int $householdId, int $targetUserId): void
    {
        if ($callerId === $targetUserId) {
            $this->requireMember($householdId, $callerId);
        } else {
            $this->requireOwner($householdId, $callerId, 'Only the household owner can remove other members.');
        }

        if ($this->members->find($householdId, $targetUserId) === null) {
            throw new NotAHouseholdMemberException('That user is not a member of this household.');
        }

        $this->members->remove($householdId, $targetUserId);
    }

    /**
     * deleteHousehold(...) - owner-only (issue #17). No bespoke cleanup
     * needed beyond the households row itself: every household-scoped
     * table's own household_id foreign key already cascades from it (see
     * HouseholdRepository::delete()), the same reasoning
     * AuthService::deleteAccount() documents for a deleted user's own data.
     */
    public function deleteHousehold(int $callerId, int $householdId): void
    {
        $this->requireOwner($householdId, $callerId, 'Only the household owner can delete the household.');
        $this->households->delete($householdId);
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

    /**
     * listShoppingList(...) - "needed" and "recently purchased" split into
     * two arrays here rather than one caller-side filter, the same shape
     * TaskService::completeInstance() etc. hand back a single list for --
     * but here the two are genuinely different queries/orderings (oldest-
     * added-first vs newest-purchased-first, see the repository), so it's
     * cheaper and clearer to return both up front.
     */
    public function listShoppingList(int $callerId, int $householdId): array
    {
        $this->requireMember($householdId, $callerId);

        return [
            'needed' => $this->shoppingItems->listNeeded($householdId),
            'recently_purchased' => $this->shoppingItems->listRecentlyPurchased($householdId),
        ];
    }

    /**
     * createShoppingItem(...)/purchaseShoppingItem(...)/
     * unpurchaseShoppingItem(...)/deleteShoppingItem(...) - a shared
     * household resource like pets, not a per-user one: any member may add,
     * buy, un-buy (a misclick's "Undo", same idea as the task Complete
     * button's own undo), or remove any item.
     */
    public function createShoppingItem(
        int $callerId,
        int $householdId,
        string $name,
        ?string $quantity,
        ?string $category
    ): array {
        $this->requireMember($householdId, $callerId);
        [$name, $quantity, $category] = $this->validateShoppingItemInput($name, $quantity, $category);

        return $this->shoppingItems->create($householdId, $callerId, $name, $quantity, $category);
    }

    public function purchaseShoppingItem(int $callerId, int $itemId): array
    {
        $item = $this->requireMemberForShoppingItem($callerId, $itemId);
        $this->shoppingItems->markPurchased((int) $item['id'], $callerId);

        return $this->shoppingItems->findById((int) $item['id']);
    }

    public function unpurchaseShoppingItem(int $callerId, int $itemId): array
    {
        $item = $this->requireMemberForShoppingItem($callerId, $itemId);
        $this->shoppingItems->markNeeded((int) $item['id']);

        return $this->shoppingItems->findById((int) $item['id']);
    }

    public function deleteShoppingItem(int $callerId, int $itemId): void
    {
        $item = $this->requireMemberForShoppingItem($callerId, $itemId);
        $this->shoppingItems->delete((int) $item['id']);
    }

    private function requireMemberForShoppingItem(int $callerId, int $itemId): array
    {
        $item = $this->shoppingItems->findById($itemId);
        if ($item === null) {
            throw new ShoppingItemNotFoundException('Shopping list item not found.');
        }

        $this->requireMember((int) $item['household_id'], $callerId);

        return $item;
    }

    private function validateShoppingItemInput(string $name, ?string $quantity, ?string $category): array
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 150) {
            throw new \InvalidArgumentException('Item name must be 1-150 characters.');
        }

        $quantity = $quantity !== null ? trim($quantity) : null;
        $quantity = $quantity === '' ? null : $quantity;
        if ($quantity !== null && strlen($quantity) > 50) {
            throw new \InvalidArgumentException('Quantity must be 50 characters or fewer.');
        }

        $category = $category !== null ? trim($category) : null;
        $category = $category === '' ? null : $category;
        if ($category !== null && strlen($category) > 50) {
            throw new \InvalidArgumentException('Category must be 50 characters or fewer.');
        }

        return [$name, $quantity, $category];
    }

    /**
     * listStaples(...)/createStaple(...)/flagStapleNeedsRestock(...)/
     * unflagStapleNeedsRestock(...)/deleteStaple(...) - a shared household
     * resource like pets/the shopping list: any member may add, flag/unflag,
     * or remove any staple. Distinct from the shopping list itself (issue
     * #24/#65) -- a staple is a standing "thing we always keep stocked"
     * definition that gets checked repeatedly, not a one-off "need to buy
     * this" entry.
     */
    public function listStaples(int $callerId, int $householdId): array
    {
        $this->requireMember($householdId, $callerId);

        return $this->staples->listAll($householdId);
    }

    public function createStaple(int $callerId, int $householdId, string $name, ?string $category): array
    {
        $this->requireMember($householdId, $callerId);
        [$name, $category] = $this->validateStapleInput($name, $category);

        return $this->staples->create($householdId, $name, $category);
    }

    public function flagStapleNeedsRestock(int $callerId, int $itemId): array
    {
        $item = $this->requireMemberForStaple($callerId, $itemId);
        $this->staples->flagNeedsRestock((int) $item['id'], $callerId);

        return $this->staples->findById((int) $item['id']);
    }

    public function unflagStapleNeedsRestock(int $callerId, int $itemId): array
    {
        $item = $this->requireMemberForStaple($callerId, $itemId);
        $this->staples->unflagNeedsRestock((int) $item['id']);

        return $this->staples->findById((int) $item['id']);
    }

    public function deleteStaple(int $callerId, int $itemId): void
    {
        $item = $this->requireMemberForStaple($callerId, $itemId);
        $this->staples->delete((int) $item['id']);
    }

    /**
     * addNeedingRestockStaplesToShoppingList(...) - the actual point of the
     * staples checklist: turn every currently-flagged staple into a real
     * shopping-list item (issue #66), then clear its flag so it doesn't get
     * copied over again next time. Returns the newly-created shopping items.
     */
    public function addNeedingRestockStaplesToShoppingList(int $callerId, int $householdId): array
    {
        $this->requireMember($householdId, $callerId);

        $created = [];
        foreach ($this->staples->listNeedingRestock($householdId) as $staple) {
            $created[] = $this->shoppingItems->create(
                $householdId,
                $callerId,
                (string) $staple['name'],
                null,
                $staple['category'] !== null ? (string) $staple['category'] : null
            );
            $this->staples->unflagNeedsRestock((int) $staple['id']);
        }

        return $created;
    }

    private function requireMemberForStaple(int $callerId, int $itemId): array
    {
        $item = $this->staples->findById($itemId);
        if ($item === null) {
            throw new StapleItemNotFoundException('Staple item not found.');
        }

        $this->requireMember((int) $item['household_id'], $callerId);

        return $item;
    }

    private function validateStapleInput(string $name, ?string $category): array
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 150) {
            throw new \InvalidArgumentException('Item name must be 1-150 characters.');
        }

        $category = $category !== null ? trim($category) : null;
        $category = $category === '' ? null : $category;
        if ($category !== null && strlen($category) > 50) {
            throw new \InvalidArgumentException('Category must be 50 characters or fewer.');
        }

        return [$name, $category];
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

    /**
     * listCalendarEvents(...)/createCalendarEvent(...) - a shared household
     * resource like pets, not a per-user one: any member may add an event.
     * Editing/deleting, unlike pets, is author-only (updateCalendarEvent()/
     * deleteCalendarEvent() below) -- same reasoning as notes: this table
     * carries private content, unlike pets/tasks/shopping/staples.
     *
     * The three-tier visibility model (issue #13) is enforced entirely
     * here, at read time, never left to the frontend: HouseholdCalendarEvent
     * Repository::listVisibleTo() already excludes another member's
     * 'private' events at the SQL level, so this only has to redact
     * 'busy' ones down to their blocked time slot -- title, description,
     * location, and who created/is responsible for it are all stripped for
     * anyone but the creator, who always sees their own events in full
     * regardless of visibility (same "you always see your own stuff"
     * principle listNotes() already establishes for private notes).
     */
    public function listCalendarEvents(int $callerId, int $householdId, string $from, string $to): array
    {
        $this->requireMember($householdId, $callerId);

        $rows = $this->calendarEvents->listVisibleTo($householdId, $callerId, $from, $to);

        return array_map(
            fn (array $row): array => $this->redactCalendarEvent($row, $callerId),
            $rows
        );
    }

    public function createCalendarEvent(
        int $callerId,
        int $householdId,
        string $title,
        ?string $description,
        string $startsAt,
        string $endsAt,
        ?string $location,
        ?int $responsibleUserId,
        string $visibility
    ): array {
        $this->requireMember($householdId, $callerId);
        [$title, $description, $startsAt, $endsAt, $location, $responsibleUserId, $visibility] =
            $this->validateCalendarEventInput($householdId, $title, $description, $startsAt, $endsAt, $location, $responsibleUserId, $visibility);

        return $this->calendarEvents->create(
            $householdId,
            $callerId,
            $title,
            $description,
            $startsAt,
            $endsAt,
            $location,
            $responsibleUserId,
            $visibility
        );
    }

    public function updateCalendarEvent(
        int $callerId,
        int $eventId,
        string $title,
        ?string $description,
        string $startsAt,
        string $endsAt,
        ?string $location,
        ?int $responsibleUserId,
        string $visibility
    ): array {
        $event = $this->requireOwnCalendarEvent($callerId, $eventId);
        [$title, $description, $startsAt, $endsAt, $location, $responsibleUserId, $visibility] =
            $this->validateCalendarEventInput((int) $event['household_id'], $title, $description, $startsAt, $endsAt, $location, $responsibleUserId, $visibility);

        $this->calendarEvents->update($eventId, $title, $description, $startsAt, $endsAt, $location, $responsibleUserId, $visibility);

        return $this->calendarEvents->findById($eventId);
    }

    public function deleteCalendarEvent(int $callerId, int $eventId): void
    {
        $event = $this->requireOwnCalendarEvent($callerId, $eventId);
        $this->calendarEvents->delete((int) $event['id']);
    }

    /**
     * redactCalendarEvent(...) - the caller's own events (any visibility)
     * and any 'public' event pass through untouched; a 'busy' event of
     * another member's is reduced to just enough to render a blocked-off
     * slot on the calendar, nothing else -- see this method family's own
     * class-level docblock note above listCalendarEvents().
     */
    private function redactCalendarEvent(array $row, int $callerId): array
    {
        if ((int) $row['created_by_user_id'] === $callerId || $row['visibility'] === 'public') {
            return $row;
        }

        return [
            'id' => (int) $row['id'],
            'household_id' => (int) $row['household_id'],
            'starts_at' => $row['starts_at'],
            'ends_at' => $row['ends_at'],
            'visibility' => $row['visibility'],
        ];
    }

    private function requireOwnCalendarEvent(int $callerId, int $eventId): array
    {
        $event = $this->calendarEvents->findById($eventId);
        if ($event === null) {
            throw new CalendarEventNotFoundException('Calendar event not found.');
        }

        $this->requireMember((int) $event['household_id'], $callerId);

        if ((int) $event['created_by_user_id'] !== $callerId) {
            throw new NotAuthorizedToModifyCalendarEventException(
                'Only the member who created this event can edit or delete it.'
            );
        }

        return $event;
    }

    private function validateCalendarEventInput(
        int $householdId,
        string $title,
        ?string $description,
        string $startsAt,
        string $endsAt,
        ?string $location,
        ?int $responsibleUserId,
        string $visibility
    ): array {
        $title = trim($title);
        if ($title === '' || strlen($title) > 150) {
            throw new \InvalidArgumentException('Event title must be 1-150 characters.');
        }

        $description = $description !== null ? trim($description) : null;
        $description = $description === '' ? null : $description;
        if ($description !== null && strlen($description) > 2000) {
            throw new \InvalidArgumentException('Description must be 2000 characters or fewer.');
        }

        $startsAtTime = strtotime($startsAt);
        $endsAtTime = strtotime($endsAt);
        if ($startsAtTime === false || $endsAtTime === false) {
            throw new \InvalidArgumentException('starts_at and ends_at must be valid dates/times.');
        }
        if ($endsAtTime <= $startsAtTime) {
            throw new \InvalidArgumentException('ends_at must be after starts_at.');
        }
        $startsAt = date('Y-m-d H:i:s', $startsAtTime);
        $endsAt = date('Y-m-d H:i:s', $endsAtTime);

        $location = $location !== null ? trim($location) : null;
        $location = $location === '' ? null : $location;
        if ($location !== null && strlen($location) > 255) {
            throw new \InvalidArgumentException('Location must be 255 characters or fewer.');
        }

        if ($responsibleUserId !== null && $this->members->find($householdId, $responsibleUserId) === null) {
            throw new \InvalidArgumentException('The responsible party must be a member of this household.');
        }

        if (!in_array($visibility, ['private', 'busy', 'public'], true)) {
            throw new \InvalidArgumentException('visibility must be "private", "busy", or "public".');
        }

        return [$title, $description, $startsAt, $endsAt, $location, $responsibleUserId, $visibility];
    }

    private function requireMember(int $householdId, int $userId): void
    {
        if ($this->members->find($householdId, $userId) === null) {
            throw new NotAHouseholdMemberException('You are not a member of this household.');
        }
    }

    /**
     * requireOwner(...) - the shared guard, alongside requireMember(), for
     * an action reserved for the household's owner (issue #17). $message is
     * caller-supplied (like NotAHouseholdMemberException's own messages
     * throughout this class) so each action names itself in the error
     * rather than a single generic "not allowed".
     */
    private function requireOwner(int $householdId, int $userId, string $message): void
    {
        $membership = $this->members->find($householdId, $userId);
        if ($membership === null) {
            throw new NotAHouseholdMemberException('You are not a member of this household.');
        }

        if ($membership['role'] !== 'owner') {
            throw new NotHouseholdOwnerException($message);
        }
    }
}

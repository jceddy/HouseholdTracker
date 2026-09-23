<?php

declare(strict_types=1);

namespace HouseholdTracker\Household;

use HouseholdTracker\Repository\HouseholdMeetingRepository;
use HouseholdTracker\Repository\HouseholdMemberRepository;
use HouseholdTracker\Repository\HouseholdTaskInstanceRepository;

/**
 * Household meetings (issue #8): when a meeting happened, who was present,
 * and notes on decisions made/issues discussed. Owns the meeting entity
 * itself (HouseholdMeetingRepository); a meeting's own action-item tasks
 * are plain household_tasks, created/edited/completed/deleted through
 * TaskService's existing routes exactly like any other task (tagged with
 * source_type = 'meeting', source_id = this meeting -- see TaskService's
 * own docblock) rather than through this service. This service's job is
 * meeting CRUD plus the one read-only "meeting + its linked tasks" view
 * the Meetings tab's detail panel needs -- same split of responsibility
 * HomeImprovementService already uses for projects/their tasks.
 *
 * Same "no privacy tiers, any member can add/edit/remove" permission
 * model as pets/contacts -- a meeting log is shared household
 * information, not one member's private content, unlike notes/calendar
 * events.
 *
 * Deleting a meeting (deleteMeeting()) deliberately doesn't cascade to its
 * linked tasks -- they simply keep existing as ordinary tasks, their
 * source_type/source_id now pointing at nothing in particular, the same
 * behavior HomeImprovementService::deleteProject() already settled on.
 */
final class HouseholdMeetingService
{
    private const MAX_NOTES_LENGTH = 20000;

    public function __construct(
        private readonly HouseholdMemberRepository $members,
        private readonly HouseholdMeetingRepository $meetings,
        private readonly HouseholdTaskInstanceRepository $instances,
    ) {
    }

    public function listMeetings(int $callerId, int $householdId): array
    {
        $this->requireMember($householdId, $callerId);

        return $this->meetings->listForHousehold($householdId);
    }

    /**
     * getMeeting(...) - a single meeting plus its own linked tasks (see
     * HouseholdTaskInstanceRepository::listForSource()'s own docblock for
     * what that list does and doesn't include).
     */
    public function getMeeting(int $callerId, int $meetingId): array
    {
        $meeting = $this->requireMeeting($meetingId);
        $this->requireMember((int) $meeting['household_id'], $callerId);

        return [
            'meeting' => $meeting,
            'tasks' => $this->instances->listForSource('meeting', (int) $meeting['id']),
        ];
    }

    public function createMeeting(
        int $callerId,
        int $householdId,
        string $occurredAt,
        array $attendeeUserIds,
        ?string $notes
    ): array {
        $this->requireMember($householdId, $callerId);
        $occurredAt = $this->validateOccurredAt($occurredAt);
        $attendeeUserIds = $this->validateAttendees($householdId, $attendeeUserIds);
        $notes = $this->validateNotes($notes);

        return $this->meetings->create($householdId, $callerId, $occurredAt, $notes, $attendeeUserIds);
    }

    public function updateMeeting(
        int $callerId,
        int $meetingId,
        string $occurredAt,
        array $attendeeUserIds,
        ?string $notes
    ): array {
        $meeting = $this->requireMeeting($meetingId);
        $this->requireMember((int) $meeting['household_id'], $callerId);
        $occurredAt = $this->validateOccurredAt($occurredAt);
        $attendeeUserIds = $this->validateAttendees((int) $meeting['household_id'], $attendeeUserIds);
        $notes = $this->validateNotes($notes);

        $this->meetings->update((int) $meeting['id'], $occurredAt, $notes, $attendeeUserIds);

        return $this->meetings->findById((int) $meeting['id']);
    }

    public function deleteMeeting(int $callerId, int $meetingId): void
    {
        $meeting = $this->requireMeeting($meetingId);
        $this->requireMember((int) $meeting['household_id'], $callerId);
        $this->meetings->delete((int) $meeting['id']);
    }

    private function requireMeeting(int $meetingId): array
    {
        $meeting = $this->meetings->findById($meetingId);
        if ($meeting === null) {
            throw new MeetingNotFoundException('Meeting not found.');
        }

        return $meeting;
    }

    private function validateOccurredAt(string $occurredAt): string
    {
        $time = strtotime($occurredAt);
        if ($time === false) {
            throw new \InvalidArgumentException('occurred_at must be a valid date/time.');
        }

        return date('Y-m-d H:i:s', $time);
    }

    private function validateAttendees(int $householdId, array $attendeeUserIds): array
    {
        $attendeeUserIds = array_values(array_unique(array_map('intval', $attendeeUserIds)));

        foreach ($attendeeUserIds as $userId) {
            if ($this->members->find($householdId, $userId) === null) {
                throw new \InvalidArgumentException('Attendees must be members of this household.');
            }
        }

        return $attendeeUserIds;
    }

    private function validateNotes(?string $notes): ?string
    {
        $notes = $notes !== null ? trim($notes) : null;
        $notes = $notes === '' ? null : $notes;
        if ($notes !== null && strlen($notes) > self::MAX_NOTES_LENGTH) {
            throw new \InvalidArgumentException('Notes must be ' . self::MAX_NOTES_LENGTH . ' characters or fewer.');
        }

        return $notes;
    }

    private function requireMember(int $householdId, int $userId): void
    {
        if ($this->members->find($householdId, $userId) === null) {
            throw new NotAHouseholdMemberException('You are not a member of this household.');
        }
    }
}

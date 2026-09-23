<?php

declare(strict_types=1);

namespace HouseholdTracker\Repository;

use HouseholdTracker\Database\Connection;

final class HouseholdMeetingRepository
{
    public function create(
        int $householdId,
        int $createdByUserId,
        string $occurredAt,
        ?string $notes,
        array $attendeeUserIds
    ): array {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'INSERT INTO household_meetings (household_id, occurred_at, notes, created_by_user_id)
             VALUES (:household_id, :occurred_at, :notes, :created_by_user_id)'
        );
        $stmt->execute([
            'household_id' => $householdId,
            'occurred_at' => $occurredAt,
            'notes' => $notes,
            'created_by_user_id' => $createdByUserId,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->replaceAttendees($id, $attendeeUserIds);

        return $this->findById($id);
    }

    public function findById(int $id): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM household_meetings WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $row['attendee_ids'] = $this->listAttendeeIds($id);

        return $row;
    }

    /**
     * listForHousehold(...) - most recent meeting first, so the Meetings
     * tab reads as a running log with the latest entry on top.
     */
    public function listForHousehold(int $householdId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM household_meetings WHERE household_id = :household_id ORDER BY occurred_at DESC'
        );
        $stmt->execute(['household_id' => $householdId]);
        $rows = $stmt->fetchAll();

        $meetingIds = array_map(fn (array $row): int => (int) $row['id'], $rows);
        $attendeesByMeeting = $this->listAttendeesForMeetings($meetingIds);

        return array_map(function (array $row) use ($attendeesByMeeting): array {
            $row['attendees'] = $attendeesByMeeting[(int) $row['id']] ?? [];

            return $row;
        }, $rows);
    }

    public function update(int $id, string $occurredAt, ?string $notes, array $attendeeUserIds): void
    {
        $stmt = Connection::get()->prepare(
            'UPDATE household_meetings SET occurred_at = :occurred_at, notes = :notes WHERE id = :id'
        );
        $stmt->execute([
            'occurred_at' => $occurredAt,
            'notes' => $notes,
            'id' => $id,
        ]);
        $this->replaceAttendees($id, $attendeeUserIds);
    }

    public function delete(int $id): void
    {
        $stmt = Connection::get()->prepare('DELETE FROM household_meetings WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * replaceAttendees(...) - wholesale replace, not a diff, same
     * "simplest correct thing for a small list edited as a whole via
     * checkboxes in the UI" approach HouseholdTaskRepository::
     * replaceAssignees() already uses for a task's assignees.
     */
    public function replaceAttendees(int $meetingId, array $userIds): void
    {
        $pdo = Connection::get();
        $pdo->prepare('DELETE FROM household_meeting_attendees WHERE meeting_id = :meeting_id')->execute(['meeting_id' => $meetingId]);

        if ($userIds === []) {
            return;
        }

        $stmt = $pdo->prepare('INSERT INTO household_meeting_attendees (meeting_id, user_id) VALUES (:meeting_id, :user_id)');
        foreach (array_unique($userIds) as $userId) {
            $stmt->execute(['meeting_id' => $meetingId, 'user_id' => $userId]);
        }
    }

    public function listAttendeeIds(int $meetingId): array
    {
        $stmt = Connection::get()->prepare('SELECT user_id FROM household_meeting_attendees WHERE meeting_id = :meeting_id');
        $stmt->execute(['meeting_id' => $meetingId]);

        return array_map('intval', array_column($stmt->fetchAll(), 'user_id'));
    }

    /**
     * listAttendeesForMeetings(...) - bulk-fetches {meeting_id, id,
     * username} for every attendee of every given meeting id in one
     * query, so listForHousehold() doesn't run one attendee query per row
     * -- same shape as HouseholdTaskRepository::listAssigneesForTasks().
     *
     * @param array<int> $meetingIds
     */
    public function listAttendeesForMeetings(array $meetingIds): array
    {
        if ($meetingIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($meetingIds), '?'));
        $stmt = Connection::get()->prepare(
            "SELECT household_meeting_attendees.meeting_id, users.id, users.username
             FROM household_meeting_attendees
             INNER JOIN users ON users.id = household_meeting_attendees.user_id
             WHERE household_meeting_attendees.meeting_id IN ({$placeholders})
             ORDER BY users.username ASC"
        );
        $stmt->execute(array_values($meetingIds));

        $byMeeting = [];
        foreach ($stmt->fetchAll() as $row) {
            $byMeeting[(int) $row['meeting_id']][] = ['id' => (int) $row['id'], 'username' => $row['username']];
        }

        return $byMeeting;
    }
}

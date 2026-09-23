<?php

declare(strict_types=1);

namespace HouseholdTracker\Repository;

use HouseholdTracker\Database\Connection;

final class HouseholdCalendarEventRepository
{
    public function create(
        int $householdId,
        int $createdByUserId,
        string $title,
        ?string $description,
        string $startsAt,
        string $endsAt,
        ?string $location,
        ?int $responsibleUserId,
        string $visibility
    ): array {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'INSERT INTO household_calendar_events
                (household_id, created_by_user_id, title, description, starts_at, ends_at, location, responsible_user_id, visibility)
             VALUES
                (:household_id, :created_by_user_id, :title, :description, :starts_at, :ends_at, :location, :responsible_user_id, :visibility)'
        );
        $stmt->execute([
            'household_id' => $householdId,
            'created_by_user_id' => $createdByUserId,
            'title' => $title,
            'description' => $description,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'location' => $location,
            'responsible_user_id' => $responsibleUserId,
            'visibility' => $visibility,
        ]);

        return $this->findById((int) $pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT household_calendar_events.*,
                    creator.username AS created_by_username,
                    responsible.username AS responsible_username
             FROM household_calendar_events
             INNER JOIN users creator ON creator.id = household_calendar_events.created_by_user_id
             LEFT JOIN users responsible ON responsible.id = household_calendar_events.responsible_user_id
             WHERE household_calendar_events.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * listVisibleTo(...) - every event overlapping [from, to] that the
     * caller either created themselves (any visibility) or didn't (only
     * 'public'/'busy' -- a 'private' event of another member's is excluded
     * at the SQL level, never even fetched into PHP, the same
     * defense-in-depth HouseholdNoteRepository::listVisibleTo() already
     * uses). Redacting a 'busy' event's details down to just the blocked
     * time slot is HouseholdService::listCalendarEvents()'s job, not this
     * method's -- this just returns full rows for whatever's allowed to be
     * fetched at all.
     */
    public function listVisibleTo(int $householdId, int $callerId, string $from, string $to): array
    {
        $stmt = Connection::get()->prepare(
            "SELECT household_calendar_events.*,
                    creator.username AS created_by_username,
                    responsible.username AS responsible_username
             FROM household_calendar_events
             INNER JOIN users creator ON creator.id = household_calendar_events.created_by_user_id
             LEFT JOIN users responsible ON responsible.id = household_calendar_events.responsible_user_id
             WHERE household_calendar_events.household_id = :household_id
               AND household_calendar_events.starts_at < :to
               AND household_calendar_events.ends_at > :from
               AND (household_calendar_events.created_by_user_id = :caller_id OR household_calendar_events.visibility IN ('public', 'busy'))
             ORDER BY household_calendar_events.starts_at ASC"
        );
        $stmt->execute([
            'household_id' => $householdId,
            'caller_id' => $callerId,
            'from' => $from,
            'to' => $to,
        ]);

        return $stmt->fetchAll();
    }

    public function update(
        int $id,
        string $title,
        ?string $description,
        string $startsAt,
        string $endsAt,
        ?string $location,
        ?int $responsibleUserId,
        string $visibility
    ): void {
        $stmt = Connection::get()->prepare(
            'UPDATE household_calendar_events
             SET title = :title, description = :description, starts_at = :starts_at, ends_at = :ends_at,
                 location = :location, responsible_user_id = :responsible_user_id, visibility = :visibility
             WHERE id = :id'
        );
        $stmt->execute([
            'title' => $title,
            'description' => $description,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'location' => $location,
            'responsible_user_id' => $responsibleUserId,
            'visibility' => $visibility,
            'id' => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = Connection::get()->prepare('DELETE FROM household_calendar_events WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}

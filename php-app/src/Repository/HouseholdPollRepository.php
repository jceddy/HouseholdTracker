<?php

declare(strict_types=1);

namespace HouseholdTracker\Repository;

use HouseholdTracker\Database\Connection;

final class HouseholdPollRepository
{
    /**
     * create(...) - inserts the poll row and its fixed option list (order
     * preserved via display_order) in one call, then returns the freshly
     * assembled poll the same way findById() would.
     *
     * @param array<int, string> $optionTexts
     */
    public function create(
        int $householdId,
        int $createdByUserId,
        string $question,
        bool $allowMultipleSelections,
        ?string $closesAt,
        array $optionTexts
    ): array {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'INSERT INTO household_polls
                (household_id, created_by_user_id, question, allow_multiple_selections, closes_at)
             VALUES (:household_id, :created_by_user_id, :question, :allow_multiple_selections, :closes_at)'
        );
        $stmt->execute([
            'household_id' => $householdId,
            'created_by_user_id' => $createdByUserId,
            'question' => $question,
            'allow_multiple_selections' => $allowMultipleSelections ? 1 : 0,
            'closes_at' => $closesAt,
        ]);
        $id = (int) $pdo->lastInsertId();

        $optionStmt = $pdo->prepare(
            'INSERT INTO household_poll_options (poll_id, option_text, display_order) VALUES (:poll_id, :option_text, :display_order)'
        );
        foreach (array_values($optionTexts) as $index => $optionText) {
            $optionStmt->execute(['poll_id' => $id, 'option_text' => $optionText, 'display_order' => $index]);
        }

        return $this->findById($id);
    }

    public function findById(int $id): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM household_polls WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $row['allow_multiple_selections'] = (bool) $row['allow_multiple_selections'];
        $row['options'] = $this->listOptionsForPolls([$id])[$id] ?? [];

        return $row;
    }

    /**
     * listForHousehold(...) - most recently created poll first, mirroring
     * HouseholdMeetingRepository::listForHousehold()'s own "latest on top"
     * ordering. Bulk-fetches every listed poll's options+votes in two
     * queries total rather than one pair per poll.
     */
    public function listForHousehold(int $householdId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM household_polls WHERE household_id = :household_id ORDER BY created_at DESC'
        );
        $stmt->execute(['household_id' => $householdId]);
        $rows = $stmt->fetchAll();

        $pollIds = array_map(fn (array $row): int => (int) $row['id'], $rows);
        $optionsByPoll = $this->listOptionsForPolls($pollIds);

        return array_map(function (array $row) use ($optionsByPoll): array {
            $row['allow_multiple_selections'] = (bool) $row['allow_multiple_selections'];
            $row['options'] = $optionsByPoll[(int) $row['id']] ?? [];

            return $row;
        }, $rows);
    }

    public function close(int $id): void
    {
        $stmt = Connection::get()->prepare("UPDATE household_polls SET status = 'closed' WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = Connection::get()->prepare('DELETE FROM household_polls WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * listOptionIds(...) - which option ids actually belong to this poll,
     * so HouseholdPollService can reject a vote for an option from a
     * different poll before it ever reaches replaceVotes().
     */
    public function listOptionIds(int $pollId): array
    {
        $stmt = Connection::get()->prepare('SELECT id FROM household_poll_options WHERE poll_id = :poll_id');
        $stmt->execute(['poll_id' => $pollId]);

        return array_map('intval', array_column($stmt->fetchAll(), 'id'));
    }

    /**
     * replaceVotes(...) - wholesale replace of one user's votes on one
     * poll, same "replace, don't diff" shape as
     * HouseholdMeetingRepository::replaceAttendees(). Doubles as both
     * "cast a vote" and "change my vote" -- there's no separate un-vote
     * call, just vote again with a different (or empty) option set.
     *
     * @param array<int> $optionIds
     */
    public function replaceVotes(int $pollId, int $userId, array $optionIds): void
    {
        $pdo = Connection::get();
        $pdo->prepare('DELETE FROM household_poll_votes WHERE poll_id = :poll_id AND user_id = :user_id')
            ->execute(['poll_id' => $pollId, 'user_id' => $userId]);

        if ($optionIds === []) {
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO household_poll_votes (poll_id, option_id, user_id) VALUES (:poll_id, :option_id, :user_id)'
        );
        foreach (array_unique($optionIds) as $optionId) {
            $stmt->execute(['poll_id' => $pollId, 'option_id' => $optionId, 'user_id' => $userId]);
        }
    }

    /**
     * listOptionsForPolls(...) - for every given poll id, its options in
     * display order, each carrying the list of {id, username} voters --
     * same bulk "one IN (...) query, grouped in PHP" shape as
     * HouseholdMeetingRepository::listAttendeesForMeetings(). Votes are
     * not anonymous (see migration 0037's own comment), so the voter list
     * doubles as both the result display and, on the frontend, whether the
     * current user already voted for a given option.
     *
     * @param array<int> $pollIds
     */
    public function listOptionsForPolls(array $pollIds): array
    {
        if ($pollIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($pollIds), '?'));
        $stmt = Connection::get()->prepare(
            "SELECT id, poll_id, option_text, display_order
             FROM household_poll_options
             WHERE poll_id IN ({$placeholders})
             ORDER BY poll_id ASC, display_order ASC, id ASC"
        );
        $stmt->execute(array_values($pollIds));
        $optionRows = $stmt->fetchAll();

        $optionIds = array_map(fn (array $row): int => (int) $row['id'], $optionRows);
        $votesByOption = $this->listVotesForOptions($optionIds);

        $byPoll = [];
        foreach ($optionRows as $row) {
            $optionId = (int) $row['id'];
            $byPoll[(int) $row['poll_id']][] = [
                'id' => $optionId,
                'option_text' => $row['option_text'],
                'display_order' => (int) $row['display_order'],
                'votes' => $votesByOption[$optionId] ?? [],
            ];
        }

        return $byPoll;
    }

    /**
     * @param array<int> $optionIds
     */
    private function listVotesForOptions(array $optionIds): array
    {
        if ($optionIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($optionIds), '?'));
        $stmt = Connection::get()->prepare(
            "SELECT household_poll_votes.option_id, users.id, users.username
             FROM household_poll_votes
             INNER JOIN users ON users.id = household_poll_votes.user_id
             WHERE household_poll_votes.option_id IN ({$placeholders})
             ORDER BY users.username ASC"
        );
        $stmt->execute(array_values($optionIds));

        $byOption = [];
        foreach ($stmt->fetchAll() as $row) {
            $byOption[(int) $row['option_id']][] = ['id' => (int) $row['id'], 'username' => $row['username']];
        }

        return $byOption;
    }
}

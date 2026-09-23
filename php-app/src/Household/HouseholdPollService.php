<?php

declare(strict_types=1);

namespace HouseholdTracker\Household;

use HouseholdTracker\Repository\HouseholdMemberRepository;
use HouseholdTracker\Repository\HouseholdPollRepository;

/**
 * Household polls (issue #27): a lightweight "ask the household, let them
 * vote" mechanism for any decision, not tied to any other tracker.
 *
 * Same "no privacy tiers, any member can create/vote/close/delete" model
 * as pets/contacts/meetings -- see "Household roles and permissions" in
 * php-app/README.md.
 *
 * Votes are not anonymous and results are always visible (no "hide until
 * you vote" gating) -- see migration 0037's own comment for the reasoning.
 * Options are fixed at creation and recurring polls are out of scope for
 * v1, settling the issue's own open questions in favor of the simpler v1.
 *
 * A poll's own vote() call always replaces the caller's full vote set for
 * that poll (HouseholdPollRepository::replaceVotes()) rather than
 * adding/removing one vote at a time -- the same "submit your whole
 * desired state, we'll wholesale-replace it" shape this app already uses
 * for meeting attendees and task assignees, which also means "change my
 * vote" needs no separate action.
 */
final class HouseholdPollService
{
    private const MAX_QUESTION_LENGTH = 500;
    private const MAX_OPTION_LENGTH = 200;
    private const MIN_OPTIONS = 2;
    private const MAX_OPTIONS = 20;

    public function __construct(
        private readonly HouseholdMemberRepository $members,
        private readonly HouseholdPollRepository $polls,
    ) {
    }

    public function listPolls(int $callerId, int $householdId): array
    {
        $this->requireMember($householdId, $callerId);

        return $this->polls->listForHousehold($householdId);
    }

    public function getPoll(int $callerId, int $pollId): array
    {
        $poll = $this->requirePoll($pollId);
        $this->requireMember((int) $poll['household_id'], $callerId);

        return $poll;
    }

    /**
     * @param array<int, string> $optionTexts
     */
    public function createPoll(
        int $callerId,
        int $householdId,
        string $question,
        bool $allowMultipleSelections,
        ?string $closesAt,
        array $optionTexts
    ): array {
        $this->requireMember($householdId, $callerId);
        $question = $this->validateQuestion($question);
        $optionTexts = $this->validateOptionTexts($optionTexts);
        $closesAt = $this->validateClosesAt($closesAt);

        return $this->polls->create($householdId, $callerId, $question, $allowMultipleSelections, $closesAt, $optionTexts);
    }

    /**
     * vote(...) - casts (or changes) the caller's vote(s) on an open poll.
     * The given option_ids become the caller's complete vote set for this
     * poll; an empty array clears their vote entirely.
     *
     * @param array<int> $optionIds
     */
    public function vote(int $callerId, int $pollId, array $optionIds): array
    {
        $poll = $this->requirePoll($pollId);
        $this->requireMember((int) $poll['household_id'], $callerId);
        $this->requireOpenForVoting($poll);

        $optionIds = array_values(array_unique(array_map('intval', $optionIds)));
        if (!$poll['allow_multiple_selections'] && count($optionIds) > 1) {
            throw new \InvalidArgumentException('This poll only allows selecting one option.');
        }

        $validOptionIds = $this->polls->listOptionIds($pollId);
        foreach ($optionIds as $optionId) {
            if (!in_array($optionId, $validOptionIds, true)) {
                throw new \InvalidArgumentException('One or more options do not belong to this poll.');
            }
        }

        $this->polls->replaceVotes($pollId, $callerId, $optionIds);

        return $this->polls->findById($pollId);
    }

    public function closePoll(int $callerId, int $pollId): array
    {
        $poll = $this->requirePoll($pollId);
        $this->requireMember((int) $poll['household_id'], $callerId);
        $this->polls->close($pollId);

        return $this->polls->findById($pollId);
    }

    public function deletePoll(int $callerId, int $pollId): void
    {
        $poll = $this->requirePoll($pollId);
        $this->requireMember((int) $poll['household_id'], $callerId);
        $this->polls->delete((int) $poll['id']);
    }

    private function requirePoll(int $pollId): array
    {
        $poll = $this->polls->findById($pollId);
        if ($poll === null) {
            throw new PollNotFoundException('Poll not found.');
        }

        return $poll;
    }

    /**
     * requireOpenForVoting(...) - same "computed from status/closes_at at
     * the moment it matters, not a stored flag" approach as isTaskOverdue()
     * on the frontend; see migration 0037's own comment.
     */
    private function requireOpenForVoting(array $poll): void
    {
        if ($poll['status'] !== 'open') {
            throw new PollClosedException('This poll is closed.');
        }

        if ($poll['closes_at'] !== null && $poll['closes_at'] <= date('Y-m-d H:i:s')) {
            throw new PollClosedException('This poll is closed.');
        }
    }

    private function validateQuestion(string $question): string
    {
        $question = trim($question);
        if ($question === '') {
            throw new \InvalidArgumentException('A poll question is required.');
        }
        if (strlen($question) > self::MAX_QUESTION_LENGTH) {
            throw new \InvalidArgumentException('The question must be ' . self::MAX_QUESTION_LENGTH . ' characters or fewer.');
        }

        return $question;
    }

    /**
     * @param array<int, string> $optionTexts
     * @return array<int, string>
     */
    private function validateOptionTexts(array $optionTexts): array
    {
        $cleaned = [];
        foreach ($optionTexts as $optionText) {
            $optionText = trim((string) $optionText);
            if ($optionText === '') {
                continue;
            }
            if (strlen($optionText) > self::MAX_OPTION_LENGTH) {
                throw new \InvalidArgumentException('Each option must be ' . self::MAX_OPTION_LENGTH . ' characters or fewer.');
            }
            $cleaned[] = $optionText;
        }

        if (count($cleaned) < self::MIN_OPTIONS) {
            throw new \InvalidArgumentException('A poll needs at least ' . self::MIN_OPTIONS . ' options.');
        }
        if (count($cleaned) > self::MAX_OPTIONS) {
            throw new \InvalidArgumentException('A poll can have at most ' . self::MAX_OPTIONS . ' options.');
        }

        return $cleaned;
    }

    private function validateClosesAt(?string $closesAt): ?string
    {
        $closesAt = $closesAt !== null ? trim($closesAt) : null;
        if ($closesAt === null || $closesAt === '') {
            return null;
        }

        $time = strtotime($closesAt);
        if ($time === false) {
            throw new \InvalidArgumentException('closes_at must be a valid date/time.');
        }
        $normalized = date('Y-m-d H:i:s', $time);
        if ($normalized <= date('Y-m-d H:i:s')) {
            throw new \InvalidArgumentException('closes_at must be in the future.');
        }

        return $normalized;
    }

    private function requireMember(int $householdId, int $userId): void
    {
        if ($this->members->find($householdId, $userId) === null) {
            throw new NotAHouseholdMemberException('You are not a member of this household.');
        }
    }
}

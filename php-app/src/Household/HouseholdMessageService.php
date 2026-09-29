<?php

declare(strict_types=1);

namespace HouseholdTracker\Household;

use HouseholdTracker\Repository\HouseholdMemberRepository;
use HouseholdTracker\Repository\HouseholdMessageRepository;

/**
 * Household chat (issue #28): a single household-wide channel, polled
 * rather than pushed in real time -- see migration 0041's own comment for
 * why (no websockets/SSE on Bluehost shared hosting), and for why this is
 * deliberately channel-only (no DMs) with permanent, un-editable messages
 * for v1.
 *
 * Same "no privacy tiers, any member can post" permission model as pets/
 * contacts/staples -- a shared household resource. Unlike those, there's
 * no update/delete here at all: once sent, a message stands.
 */
final class HouseholdMessageService
{
    private const MAX_BODY_LENGTH = 2000;
    private const HISTORY_LIMIT = 200;

    public function __construct(
        private readonly HouseholdMemberRepository $members,
        private readonly HouseholdMessageRepository $messages,
    ) {
    }

    /**
     * listMessages(...) - $sinceId (the highest message id the caller has
     * already seen) makes this a cheap incremental poll; omit it for the
     * initial page load, which returns the most recent
     * self::HISTORY_LIMIT messages instead of the household's entire
     * history.
     */
    public function listMessages(int $callerId, int $householdId, ?int $sinceId): array
    {
        $this->requireMember($householdId, $callerId);

        return $this->messages->listForHousehold($householdId, $sinceId, self::HISTORY_LIMIT);
    }

    public function sendMessage(int $callerId, int $householdId, string $body): array
    {
        $this->requireMember($householdId, $callerId);
        $body = $this->validateBody($body);

        return $this->messages->create($householdId, $callerId, $body);
    }

    private function validateBody(string $body): string
    {
        $body = trim($body);
        if ($body === '' || strlen($body) > self::MAX_BODY_LENGTH) {
            throw new \InvalidArgumentException('Message must be 1-' . self::MAX_BODY_LENGTH . ' characters.');
        }

        return $body;
    }

    private function requireMember(int $householdId, int $userId): void
    {
        if ($this->members->find($householdId, $userId) === null) {
            throw new NotAHouseholdMemberException('You are not a member of this household.');
        }
    }
}

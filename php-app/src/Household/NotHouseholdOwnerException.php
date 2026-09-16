<?php

declare(strict_types=1);

namespace HouseholdTracker\Household;

/**
 * Thrown by HouseholdService::requireOwner() (issue #17) -- the shared
 * guard for any action reserved for the household's owner, currently
 * removing another member and deleting the household outright. Callers
 * supply their own action-specific message, same as
 * NotAHouseholdMemberException.
 */
final class NotHouseholdOwnerException extends \RuntimeException
{
}

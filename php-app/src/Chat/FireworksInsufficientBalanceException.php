<?php

declare(strict_types=1);

namespace HouseholdTracker\Chat;

use RuntimeException;

/**
 * Thrown by FireworksClient::chatCompletion() when Fireworks responds with HTTP 402 (the
 * standard status for "account balance exhausted"). Kept distinct from a generic RuntimeException
 * so callers can tell "the model call failed for some reason" apart from "the account needs
 * topping up" without parsing Fireworks' error message text.
 */
final class FireworksInsufficientBalanceException extends RuntimeException
{
}

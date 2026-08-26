<?php

declare(strict_types=1);

namespace HouseholdTracker\Maintenance;

use HouseholdTracker\Database\Connection;

/**
 * Blocks app traffic when the deployed code (the repo-root VERSION file)
 * doesn't match the version the database schema last confirmed via a
 * migration -- see "Versioning" in the top-level README and "Adding a new
 * migration" in database/README.md for the convention this depends on.
 */
final class MaintenanceGate
{
    private const MESSAGE = 'HouseholdTracker is being updated and will be back shortly. Please try again in a few minutes.';

    public static function activeMessage(): ?string
    {
        return self::check(self::deployedVersion());
    }

    /**
     * Resolves the repo-root VERSION file's contents. Deployed, this class
     * sits at dist/src/Maintenance/MaintenanceGate.php with dist/VERSION a
     * sibling of dist/src. In a local checkout it instead sits at
     * php-app/src/Maintenance/MaintenanceGate.php, one directory level
     * deeper relative to the repo-root VERSION file -- so both layouts are
     * tried rather than hardcoding one dirname() depth.
     */
    public static function deployedVersion(): string
    {
        $appRoot = dirname(__DIR__, 2);

        foreach ([$appRoot . '/VERSION', dirname($appRoot) . '/VERSION'] as $candidate) {
            if (is_file($candidate)) {
                return trim((string) @file_get_contents($candidate));
            }
        }

        return '';
    }

    /**
     * @return string|null null if the app should run normally; the
     *     user-facing maintenance message if it shouldn't. Takes the
     *     deployed version as a parameter (rather than only reading the
     *     real file internally) so this comparison is unit-testable
     *     without touching the real repo-root VERSION file.
     */
    public static function check(string $deployedVersion): ?string
    {
        $deployedVersion = trim($deployedVersion);
        if ($deployedVersion === '') {
            // Can't determine the deployed version at all -- fail open
            // rather than lock everyone out over an unrelated file-read
            // problem.
            return null;
        }

        try {
            $dbVersion = Connection::get()->query('SELECT version FROM schema_version WHERE id = 1')->fetchColumn();
        } catch (\Throwable $e) {
            // schema_version missing (a migration is pending -- this IS the
            // condition being detected) or the DB is otherwise unreachable
            // both fail closed and share the same client-facing message,
            // but are logged distinctly so the two remain distinguishable
            // after the fact.
            self::logFailure($e);
            return self::MESSAGE;
        }

        if (!is_string($dbVersion) || trim($dbVersion) !== $deployedVersion) {
            return self::MESSAGE;
        }

        return null;
    }

    private static function logFailure(\Throwable $e): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] MaintenanceGate DB check failed: ' . $e->getMessage() . "\n";
        error_log($line, 3, dirname(__DIR__) . '/maintenance-errors.log');
    }
}

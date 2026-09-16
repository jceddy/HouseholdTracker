<?php

declare(strict_types=1);

namespace HouseholdTracker\Auth;

use HouseholdTracker\Household\HomeImprovementService;
use HouseholdTracker\Household\HouseholdService;
use HouseholdTracker\Household\TaskService;
use HouseholdTracker\Ledger\Ledger;
use HouseholdTracker\Repository\UserRepository;

/**
 * exportForUser(...) (issue #21) - a per-user, JSON-only, synchronous "get
 * your data back" dump: everything the requesting user themselves has
 * access to, for every household they belong to. Deliberately built on top
 * of each tracker's own already-privacy-respecting list method (e.g.
 * HouseholdService::listNotes() already filters to public notes plus the
 * caller's own private ones) rather than querying tables directly -- the
 * same guarantee those methods already give the live UI applies here
 * automatically, with no separate privacy logic to get wrong a second time.
 *
 * "Current state", not a full historical record: task instances are
 * whatever listTasks()/listFinishedToday() already return (the soonest-due
 * pending instance per task, plus today's resolved ones) -- the same shape
 * the Tasks tab itself shows, not every completion ever recorded. A true
 * full history is issue #19's (household activity log) territory; revisit
 * this export once that exists rather than building a second, bespoke deep
 * history query for it now.
 *
 * No household-wide/owner-only export exists (or is planned) alongside
 * this -- see "Data export" in php-app/README.md for why that's a
 * deliberate v1 decision, not an oversight.
 */
final class AccountExportService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly HouseholdService $households,
        private readonly TaskService $tasks,
        private readonly HomeImprovementService $homeImprovement,
        private readonly Ledger $ledger,
    ) {
    }

    public function exportForUser(int $userId): array
    {
        $user = $this->users->findById($userId);

        $householdExports = [];
        foreach ($this->households->listHouseholdsForUser($userId) as $household) {
            $householdId = (int) $household['id'];

            $householdExports[] = [
                'id' => $householdId,
                'name' => $household['name'],
                'role' => $household['role'],
                'created_at' => $household['created_at'],
                'members' => $this->households->listMembers($userId, $householdId),
                'notes' => $this->households->listNotes($userId, $householdId),
                'pets' => $this->households->listPets($userId, $householdId),
                'shopping_list' => $this->households->listShoppingList($userId, $householdId),
                'staples' => $this->households->listStaples($userId, $householdId),
                'tasks' => $this->tasks->listTasks($userId, $householdId),
                'tasks_finished_today' => $this->tasks->listFinishedToday($userId, $householdId),
                'home_improvement_projects' => $this->homeImprovement->listProjects($userId, $householdId),
            ];
        }

        return [
            'exported_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'account' => [
                'id' => (int) $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'created_at' => $user['created_at'],
            ],
            'chat_usage' => $this->ledger->usageForUser($userId),
            'households' => $householdExports,
        ];
    }
}

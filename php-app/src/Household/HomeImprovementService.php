<?php

declare(strict_types=1);

namespace HouseholdTracker\Household;

use HouseholdTracker\Repository\HomeImprovementProjectRepository;
use HouseholdTracker\Repository\HouseholdMemberRepository;
use HouseholdTracker\Repository\HouseholdTaskInstanceRepository;

/**
 * Home improvement projects and maintenance (issue #11). Owns the project
 * entity itself (HomeImprovementProjectRepository); a project's own tasks
 * and maintenance items are plain household_tasks, created/edited/
 * completed/deleted through TaskService's existing routes exactly like any
 * other task (tagged with source_type/source_id -- see TaskService's own
 * docblock) rather than through this service. This service's job is
 * project CRUD, plus the two read-only filtered task views (a project's
 * own tasks, and the household's maintenance schedule) the Home
 * Improvement tab needs.
 *
 * Deleting a project (deleteProject()) deliberately doesn't cascade to its
 * linked tasks -- they simply keep existing as ordinary tasks, their
 * source_type/source_id now pointing at nothing in particular (same
 * unenforced-reference reasoning as source_type/source_id having no FK to
 * begin with -- see database/migrations/0008_add_household_tasks.sql's own
 * comment). A member who wants those tasks gone too can delete them the
 * same way as any other task, via TaskService::deleteInstance().
 */
final class HomeImprovementService
{
    private const STATUSES = ['idea', 'planned', 'in_progress', 'completed', 'abandoned'];
    private const MAX_TITLE_LENGTH = 150;
    private const MAX_DESCRIPTION_LENGTH = 2000;
    private const MAX_COST = 99_999_999.99;

    public function __construct(
        private readonly HouseholdMemberRepository $members,
        private readonly HomeImprovementProjectRepository $projects,
        private readonly HouseholdTaskInstanceRepository $instances,
    ) {
    }

    public function listProjects(int $callerId, int $householdId): array
    {
        $this->requireMember($householdId, $callerId);

        return $this->projects->listForHousehold($householdId);
    }

    /**
     * getProject(...) - a single project plus its own linked tasks (see
     * HouseholdTaskInstanceRepository::listForSource()'s own docblock for
     * what that list does and doesn't include).
     */
    public function getProject(int $callerId, int $projectId): array
    {
        $project = $this->requireProject($projectId);
        $this->requireMember((int) $project['household_id'], $callerId);

        return [
            'project' => $project,
            'tasks' => $this->instances->listForSource('home_improvement_project', (int) $project['id']),
        ];
    }

    public function createProject(
        int $callerId,
        int $householdId,
        string $title,
        ?string $description,
        ?string $status,
        ?string $estimatedCost,
        ?string $targetDate
    ): array {
        $this->requireMember($householdId, $callerId);
        [$title, $description] = $this->validateTitleAndDescription($title, $description);
        $status = $this->validateStatus($status);
        $estimatedCost = $this->validateCost($estimatedCost, 'estimated_cost');
        $targetDate = $this->validateDate($targetDate, 'target_date');

        return $this->projects->create($householdId, $callerId, $title, $description, $status, $estimatedCost, $targetDate);
    }

    /**
     * updateProject(...) - the full project record, including
     * `actual_cost` (absent from createProject() -- there's nothing to
     * report actual spend on before a project even exists).
     */
    public function updateProject(
        int $callerId,
        int $projectId,
        string $title,
        ?string $description,
        ?string $status,
        ?string $estimatedCost,
        ?string $actualCost,
        ?string $targetDate
    ): array {
        $project = $this->requireProject($projectId);
        $this->requireMember((int) $project['household_id'], $callerId);
        [$title, $description] = $this->validateTitleAndDescription($title, $description);
        $status = $this->validateStatus($status);
        $estimatedCost = $this->validateCost($estimatedCost, 'estimated_cost');
        $actualCost = $this->validateCost($actualCost, 'actual_cost');
        $targetDate = $this->validateDate($targetDate, 'target_date');

        $this->projects->update((int) $project['id'], $title, $description, $status, $estimatedCost, $actualCost, $targetDate);

        return $this->projects->findById((int) $project['id']);
    }

    public function deleteProject(int $callerId, int $projectId): void
    {
        $project = $this->requireProject($projectId);
        $this->requireMember((int) $project['household_id'], $callerId);
        $this->projects->delete((int) $project['id']);
    }

    /**
     * listMaintenance(...) - see HouseholdTaskInstanceRepository::
     * listMaintenanceForHousehold()'s own docblock.
     */
    public function listMaintenance(int $callerId, int $householdId): array
    {
        $this->requireMember($householdId, $callerId);

        return $this->instances->listMaintenanceForHousehold($householdId);
    }

    private function requireProject(int $projectId): array
    {
        $project = $this->projects->findById($projectId);
        if ($project === null) {
            throw new ProjectNotFoundException('Project not found.');
        }

        return $project;
    }

    private function validateTitleAndDescription(string $title, ?string $description): array
    {
        $title = trim($title);
        if ($title === '' || strlen($title) > self::MAX_TITLE_LENGTH) {
            throw new \InvalidArgumentException('Project title must be 1-' . self::MAX_TITLE_LENGTH . ' characters.');
        }

        $description = $description !== null ? trim($description) : null;
        $description = $description === '' ? null : $description;
        if ($description !== null && strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            throw new \InvalidArgumentException('Project description must be ' . self::MAX_DESCRIPTION_LENGTH . ' characters or fewer.');
        }

        return [$title, $description];
    }

    private function validateStatus(?string $status): string
    {
        $status = $status !== null && $status !== '' ? $status : 'idea';
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('status must be one of: ' . implode(', ', self::STATUSES) . '.');
        }

        return $status;
    }

    /**
     * validateCost(...) - shared by estimated_cost/actual_cost: blank
     * becomes null (no cost recorded, distinct from a real $0), otherwise
     * a non-negative number up to home_improvement_projects' own
     * DECIMAL(10, 2) column width.
     */
    private function validateCost(?string $cost, string $fieldName): ?string
    {
        $cost = $cost !== null ? trim($cost) : null;
        if ($cost === '') {
            $cost = null;
        }
        if ($cost === null) {
            return null;
        }

        if (!is_numeric($cost) || (float) $cost < 0 || (float) $cost > self::MAX_COST) {
            throw new \InvalidArgumentException($fieldName . ' must be a non-negative number up to ' . self::MAX_COST . '.');
        }

        return number_format((float) $cost, 2, '.', '');
    }

    private function validateDate(?string $date, string $fieldName): ?string
    {
        $date = $date !== null ? trim($date) : null;
        if ($date === '') {
            $date = null;
        }
        if ($date === null) {
            return null;
        }

        $parsed = \DateTime::createFromFormat('Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException($fieldName . ' must be in YYYY-MM-DD format.');
        }

        return $date;
    }

    private function requireMember(int $householdId, int $userId): void
    {
        if ($this->members->find($householdId, $userId) === null) {
            throw new NotAHouseholdMemberException('You are not a member of this household.');
        }
    }
}

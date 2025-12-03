<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command\Trait;

use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Activity\ActivityInterface;
use Tcrawf\Zebra\Activity\ActivityRepositoryInterface;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;

/**
 * Trait for resolving activities by alias, ID, or name search.
 * Requires ActivityRepositoryInterface and ProjectRepositoryInterface dependencies.
 */
trait ActivityResolutionTrait
{
    /**
     * Resolve an activity by alias, ID, or name search.
     * First tries exact match by alias, then by ID, then searches by name/alias.
     * Prompts user if multiple matches are found.
     *
     * @param string $identifier Activity alias, ID, or search string
     * @param SymfonyStyle $io
     * @return ActivityInterface|null
     */
    protected function resolveActivity(string $identifier, SymfonyStyle $io): ?ActivityInterface
    {
        // First, try to find by exact activity alias match
        $activity = $this->getActivityRepository()->getByAlias($identifier);
        if ($activity !== null) {
            return $activity;
        }

        // Try to find by UUID (for local activities)
        // Only try if identifier looks like a UUID (8 hex characters, not purely numeric)
        if (strlen($identifier) === 8 && ctype_xdigit($identifier) && !ctype_digit($identifier)) {
            try {
                $entityKey = EntityKey::local($identifier);
                $activity = $this->getActivityRepository()->get($entityKey);
                if ($activity !== null) {
                    return $activity;
                }
            } catch (\InvalidArgumentException $e) {
                // Not a valid UUID, continue to other methods
            }
        }

        // If not found by alias or UUID, try to find by exact activity ID match (for Zebra activities)
        if (ctype_digit($identifier)) {
            $entityKey = EntityKey::zebra((int) $identifier);
            $activity = $this->getActivityRepository()->get($entityKey);
            if ($activity !== null) {
                return $activity;
            }
        }

        // If no exact match, search activities by alias and projects by name
        $activityMatches = $this->getActivityRepository()->searchByAlias($identifier);
        $projectMatches = $this->getProjectRepository()->getByNameLike($identifier);

        // Collect all activities from matching projects
        $activitiesFromProjects = [];
        foreach ($projectMatches as $project) {
            // Only include activities from active projects (status === 1) unless overridden
            if ($this->shouldIncludeInactiveProjects() || $project->status === 1) {
                foreach ($project->activities as $activity) {
                    $activitiesFromProjects[] = $activity;
                }
            }
        }

        // Combine activity matches (by alias) and activities from projects (by name)
        // Use activity ID as key to avoid duplicates
        $allMatches = [];
        $seenActivityKeys = [];
        foreach ($activityMatches as $activity) {
            $key = $activity->entityKey->toString();
            if (!isset($seenActivityKeys[$key])) {
                $allMatches[] = $activity;
                $seenActivityKeys[$key] = true;
            }
        }
        foreach ($activitiesFromProjects as $activity) {
            $key = $activity->entityKey->toString();
            if (!isset($seenActivityKeys[$key])) {
                $allMatches[] = $activity;
                $seenActivityKeys[$key] = true;
            }
        }

        if (empty($allMatches)) {
            return null;
        }

        if (count($allMatches) === 1) {
            return $allMatches[0];
        }

        // Multiple matches found, group by project and prompt user to select
        return $this->promptForActivitySelection($allMatches, $identifier, $io);
    }

    /**
     * Prompt user to select an activity from multiple matches.
     *
     * @param array<ActivityInterface> $allMatches
     * @param string $identifier
     * @param SymfonyStyle $io
     * @return ActivityInterface
     */
    private function promptForActivitySelection(
        array $allMatches,
        string $identifier,
        SymfonyStyle $io
    ): ActivityInterface {
        $activitiesByProject = [];
        foreach ($allMatches as $activity) {
            // Extract project ID from entityKey (for Zebra source)
            $projectEntityKey = $activity->projectEntityKey;
            $projectId = $projectEntityKey->source === EntitySource::Zebra && is_int($projectEntityKey->id)
                ? $projectEntityKey->id
                : $projectEntityKey->toString();
            if (!isset($activitiesByProject[$projectId])) {
                $activitiesByProject[$projectId] = [];
            }
            $activitiesByProject[$projectId][] = $activity;
        }

        // Sort projects by name for consistent display
        $projectIds = array_keys($activitiesByProject);
        $projects = [];
        foreach ($projectIds as $projectId) {
            // For Zebra source, use int ID; otherwise use string key
            $project = is_int($projectId)
                ? $this->getProjectRepository()->get(EntityKey::zebra($projectId))
                : null;
            if ($project !== null) {
                $projects[$projectId] = $project;
            }
        }
        usort($projectIds, static function ($a, $b) use ($projects): int {
            $nameA = isset($projects[$a]) ? $projects[$a]->name : '';
            $nameB = isset($projects[$b]) ? $projects[$b]->name : '';
            return strcasecmp($nameA, $nameB);
        });

        // Display grouped list visually
        $io->writeln("Multiple activities found matching '{$identifier}':");
        $io->newLine();

        $activityList = []; // Map index (1-based) to activity
        $index = 0;

        // Build options grouped by project
        foreach ($projectIds as $projectId) {
            $project = $projects[$projectId] ?? null;
            $projectName = $project !== null ? $project->name : "Project {$projectId}";

            // Add status indicator (status 1 = active, status 0 = inactive)
            $statusLabel = '';
            if ($project !== null) {
                $statusLabel = $project->status === 1
                    ? ' <info>(active)</info>'
                    : ' <fg=red>(inactive)</fg=red>';
            }

            // Display project header
            $io->writeln("  <comment>━━━ {$projectName}{$statusLabel} ━━━</comment>");

            // Display activities for this project
            foreach ($activitiesByProject[$projectId] as $activity) {
                $index++;

                // Build display name with ANSI codes for visual display
                $displayPartsAnsi = [];
                $displayPartsAnsi[] = $projectName . $statusLabel;
                $displayPartsAnsi[] = $activity->name;
                if ($activity->alias !== null) {
                    $displayPartsAnsi[] = "(alias: <fg=cyan>{$activity->alias}</fg=cyan>)";
                }
                $displayNameAnsi = implode(' - ', $displayPartsAnsi);

                $io->writeln("  [{$index}] {$displayNameAnsi}");
                $activityList[$index] = $activity;
            }
            $io->newLine();
        }

        // Prompt for activity number
        $maxIndex = $index;
        $selectedNumber = $io->ask(
            "Please select an activity (1-{$maxIndex}):",
            null,
            static function ($value) use ($maxIndex): int {
                $num = (int) $value;
                if ($num < 1 || $num > $maxIndex) {
                    throw new \InvalidArgumentException("Please enter a number between 1 and {$maxIndex}");
                }
                return $num;
            }
        );

        // Return the selected activity
        return $activityList[$selectedNumber];
    }

    /**
     * Determine if inactive projects should be included in activity search.
     * Override to return true for commands that should include inactive projects.
     *
     * @return bool
     */
    protected function shouldIncludeInactiveProjects(): bool
    {
        return false;
    }

    /**
     * Get activity repository instance.
     * Must be implemented by classes using this trait.
     *
     * @return ActivityRepositoryInterface
     */
    abstract protected function getActivityRepository(): ActivityRepositoryInterface;

    /**
     * Get project repository instance.
     * Must be implemented by classes using this trait.
     *
     * @return ProjectRepositoryInterface
     */
    abstract protected function getProjectRepository(): ProjectRepositoryInterface;
}

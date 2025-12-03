<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command\Autocompletion;

use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;

class LocalActivityAutocompletion
{
    public function __construct(
        private readonly ProjectRepositoryInterface $projectRepository
    ) {
    }

    /**
     * Provide local activity completion suggestions (aliases, names, and UUIDs).
     *
     * @param CompletionInput $input
     * @param CompletionSuggestions $suggestions
     * @return void
     */
    public function suggest(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        $incomplete = $input->getCompletionValue();

        $projects = $this->projectRepository->all([]);
        $aliases = [];
        $names = [];
        $uuids = [];

        foreach ($projects as $project) {
            foreach ($project->activities as $activity) {
                // Only include local activities
                if ($activity->entityKey->source !== EntitySource::Local) {
                    continue;
                }

                // Collect aliases separately to prioritize them
                if ($activity->alias !== null) {
                    $aliases[] = $activity->alias;
                }
                // Collect activity names
                $names[] = $activity->name;
                // Collect UUIDs
                $uuids[] = $activity->entityKey->toString();
            }
        }

        // Remove duplicates and reindex arrays
        $aliases = array_values(array_unique($aliases));
        $names = array_values(array_unique($names));
        $uuids = array_values(array_unique($uuids));
        $incompleteLower = strtolower($incomplete);

        // First suggest aliases (prioritized)
        foreach ($aliases as $alias) {
            $aliasLower = strtolower($alias);
            if (str_starts_with($aliasLower, $incompleteLower)) {
                $suggestions->suggestValue($alias);
            }
        }

        // Then suggest activity names
        foreach ($names as $name) {
            // Skip if name is same as an alias (already suggested)
            if (!in_array($name, $aliases, true)) {
                if (str_starts_with(strtolower($name), $incompleteLower)) {
                    $suggestions->suggestValue($name);
                }
            }
        }

        // Finally suggest UUIDs
        foreach ($uuids as $uuid) {
            if (str_starts_with(strtolower($uuid), $incompleteLower)) {
                $suggestions->suggestValue($uuid);
            }
        }
    }
}

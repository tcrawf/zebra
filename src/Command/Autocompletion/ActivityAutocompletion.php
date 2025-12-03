<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command\Autocompletion;

use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;

class ActivityAutocompletion
{
    public function __construct(
        private readonly ProjectRepositoryInterface $projectRepository
    ) {
    }

    /**
     * Provide activity completion suggestions.
     *
     * @param CompletionInput $input
     * @param CompletionSuggestions $suggestions
     * @return void
     */
    public function suggest(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        $incomplete = $input->getCompletionValue();

        $projects = $this->projectRepository->all();
        $aliases = [];
        $names = [];

        foreach ($projects as $project) {
            foreach ($project->activities as $activity) {
                // Collect aliases separately to prioritize them
                if ($activity->alias !== null) {
                    $aliases[] = $activity->alias;
                }
                // Collect activity names
                $names[] = $activity->name;
            }
        }

        // Remove duplicates and reindex arrays
        $aliases = array_values(array_unique($aliases));
        $names = array_values(array_unique($names));
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
    }
}

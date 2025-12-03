<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command\Autocompletion;

use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;

class LocalProjectAutocompletion
{
    public function __construct(
        private readonly ProjectRepositoryInterface $projectRepository
    ) {
    }

    /**
     * Provide local project completion suggestions (names and UUIDs).
     *
     * @param CompletionInput $input
     * @param CompletionSuggestions $suggestions
     * @return void
     */
    public function suggest(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        $incomplete = $input->getCompletionValue();

        $projects = $this->projectRepository->all([]);
        $incompleteLower = strtolower($incomplete);

        foreach ($projects as $project) {
            // Only suggest local projects
            if ($project->entityKey->source !== EntitySource::Local) {
                continue;
            }

            $projectName = $project->name;
            $projectUuid = $project->entityKey->toString();

            // Suggest project name if it matches
            if (str_starts_with(strtolower($projectName), $incompleteLower)) {
                $suggestions->suggestValue($projectName);
            }

            // Suggest UUID if it matches (case-insensitive for hex)
            if (str_starts_with(strtolower($projectUuid), $incompleteLower)) {
                $suggestions->suggestValue($projectUuid);
            }
        }
    }
}

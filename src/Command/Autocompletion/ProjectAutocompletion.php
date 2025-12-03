<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command\Autocompletion;

use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;

class ProjectAutocompletion
{
    public function __construct(
        private readonly ProjectRepositoryInterface $projectRepository
    ) {
    }

    /**
     * Provide project completion suggestions (names, UUIDs for local projects, IDs for Zebra projects).
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
            $projectName = $project->name;
            $projectId = $project->entityKey->toString();

            // Suggest project name if it matches
            if (str_starts_with(strtolower($projectName), $incompleteLower)) {
                $suggestions->suggestValue($projectName);
            }

            // Suggest UUID/ID if it matches (case-insensitive for hex)
            if (str_starts_with(strtolower($projectId), $incompleteLower)) {
                $suggestions->suggestValue($projectId);
            }
        }
    }
}

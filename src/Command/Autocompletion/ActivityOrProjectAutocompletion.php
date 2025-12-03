<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command\Autocompletion;

use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;

class ActivityOrProjectAutocompletion
{
    public function __construct(
        private readonly ActivityAutocompletion $activityAutocompletion,
        private readonly ProjectAutocompletion $projectAutocompletion
    ) {
    }

    /**
     * Provide combined activity and project completion suggestions.
     *
     * @param CompletionInput $input
     * @param CompletionSuggestions $suggestions
     * @return void
     */
    public function suggest(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        // First suggest activities
        $this->activityAutocompletion->suggest($input, $suggestions);

        // Then suggest projects
        $this->projectAutocompletion->suggest($input, $suggestions);
    }
}

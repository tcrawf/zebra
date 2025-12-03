<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command;

use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Activity\ActivityRepositoryInterface;
use Tcrawf\Zebra\Command\Autocompletion\LocalActivityAutocompletion;
use Tcrawf\Zebra\Command\Trait\ActivityResolutionTrait;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Project\ProjectInterface;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;

class ActivitiesCommand extends Command
{
    use ActivityResolutionTrait;

    public function __construct(
        private readonly ProjectRepositoryInterface $projectRepository,
        private readonly ActivityRepositoryInterface $activityRepository,
        private readonly LocalActivityAutocompletion $autocompletion
    ) {
        parent::__construct();
    }

    protected function getActivityRepository(): ActivityRepositoryInterface
    {
        return $this->activityRepository;
    }

    protected function getProjectRepository(): ProjectRepositoryInterface
    {
        return $this->projectRepository;
    }

    protected function configure(): void
    {
        $this
            ->setName('activities')
            ->setDescription('Display the list of all activities or add/edit a local activity')
            ->addArgument(
                'name',
                InputArgument::OPTIONAL,
                'Activity name (required when adding) or identifier (required when editing)'
            )
            ->addOption('add', 'a', InputOption::VALUE_NONE, 'Add a new local activity')
            ->addOption('edit', 'e', InputOption::VALUE_NONE, 'Edit an existing local activity')
            ->addOption(
                'project',
                'p',
                InputOption::VALUE_REQUIRED,
                'Project name (optional when adding, will prompt if not provided)'
            )
            ->addOption(
                'description',
                'd',
                InputOption::VALUE_REQUIRED,
                'Activity description. For add, defaults to empty if not provided. ' .
                'For edit, only updates if provided.'
            )
            ->addOption('alias', null, InputOption::VALUE_REQUIRED, 'Activity alias (for add) or new alias (for edit)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'New activity name (for edit only)')
            ->addOption('local', 'l', InputOption::VALUE_NONE, 'List only local activities');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('add')) {
            return $this->addActivity($input, $io);
        }

        if ($input->getOption('edit')) {
            return $this->editActivity($input, $io);
        }

        $localOnly = $input->getOption('local');
        return $this->listActivities($io, $localOnly);
    }

    /**
     * List all activities.
     *
     * @param bool $localOnly If true, only list local activities
     */
    private function listActivities(SymfonyStyle $io, bool $localOnly = false): int
    {
        $projects = $this->projectRepository->all();
        $activities = [];

        foreach ($projects as $project) {
            foreach ($project->activities as $activity) {
                // Filter to local activities only if --local option is set
                if ($localOnly && $activity->entityKey->source !== EntitySource::Local) {
                    continue;
                }

                $activities[] = [
                    'activity' => $activity,
                    'projectName' => $project->name,
                ];
            }
        }

        if (empty($activities)) {
            $io->info('No activities found.');
            return Command::SUCCESS;
        }

        // Sort by project name, then by activity name
        usort($activities, static function ($a, $b) {
            $projectCompare = strcasecmp($a['projectName'], $b['projectName']);
            if ($projectCompare !== 0) {
                return $projectCompare;
            }
            return strcasecmp($a['activity']->name, $b['activity']->name);
        });

        foreach ($activities as $item) {
            $activity = $item['activity'];
            $projectName = $item['projectName'];
            $activityId = $activity->entityKey->toString();

            if ($activity->alias !== null) {
                $io->writeln(
                    sprintf('[%s] %s - %s (%s)', $activityId, $projectName, $activity->name, $activity->alias)
                );
            } else {
                $io->writeln(sprintf('[%s] %s - %s', $activityId, $projectName, $activity->name));
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Add a new local activity.
     */
    private function addActivity(InputInterface $input, SymfonyStyle $io): int
    {
        $name = $input->getArgument('name');
        if ($name === null || $name === '') {
            $question = new Question('Activity name: ');
            $name = $io->askQuestion($question);
            if ($name === null || $name === '') {
                $io->error('Activity name is required.');
                return Command::FAILURE;
            }
        }

        $projectName = $input->getOption('project');
        $project = null;

        if ($projectName === null || $projectName === '') {
            // No project specified, prompt user to select from local projects
            $project = $this->promptForLocalProject($io);
            if ($project === null) {
                return Command::FAILURE;
            }
        } else {
            // Project name provided, find it
            $project = $this->findLocalProject($projectName, $io);
            if ($project === null) {
                return Command::FAILURE;
            }
        }

        $description = $input->getOption('description') ?? '';
        $alias = $input->getOption('alias');

        try {
            $activity = $this->activityRepository->create(
                $name,
                $description,
                $project->entityKey,
                $alias
            );

            $io->success('Activity created successfully.');
            $io->writeln(sprintf('Activity: %s', $activity->name));
            $io->writeln(sprintf('Project: %s', $project->name));
            if ($activity->alias !== null) {
                $io->writeln(sprintf('Alias: %s', $activity->alias));
            }
            if ($activity->description !== '') {
                $io->writeln(sprintf('Description: %s', $activity->description));
            }

            return Command::SUCCESS;
        } catch (InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        } catch (\Exception $e) {
            $io->error('An error occurred: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Find a local project by name.
     * Returns null if not found or if the project is not local.
     */
    private function findLocalProject(string $projectName, SymfonyStyle $io): ?ProjectInterface
    {
        $projects = $this->projectRepository->getByNameLike($projectName);

        // Filter to only local projects
        $localProjects = array_filter(
            $projects,
            static fn(ProjectInterface $project) => $project->entityKey->source === EntitySource::Local
        );

        if (empty($localProjects)) {
            $io->error("No local project found matching '{$projectName}'.");
            return null;
        }

        // If exact match found, return it
        foreach ($localProjects as $project) {
            if (strcasecmp(trim($project->name), trim($projectName)) === 0) {
                return $project;
            }
        }

        // If multiple matches, return the first one (they're already sorted)
        if (count($localProjects) === 1) {
            return reset($localProjects);
        }

        // Multiple matches - show error with suggestions
        $io->error("Multiple local projects found matching '{$projectName}':");
        foreach ($localProjects as $project) {
            $io->writeln(sprintf('  - %s', $project->name));
        }
        $io->writeln('Please specify the exact project name.');

        return null;
    }

    /**
     * Prompt user to select a local project from a list.
     * Returns null if no local projects are available.
     */
    private function promptForLocalProject(SymfonyStyle $io): ?ProjectInterface
    {
        // Get all local projects
        $allProjects = $this->projectRepository->all([]);
        $localProjects = array_filter(
            $allProjects,
            static fn(ProjectInterface $project) => $project->entityKey->source === EntitySource::Local
        );

        if (empty($localProjects)) {
            $io->error('No local projects found. Please create a local project first.');
            return null;
        }

        // Sort projects by name
        usort($localProjects, static fn($a, $b) => strcasecmp($a->name, $b->name));

        // Build options for choice question
        $projectOptions = [];
        $projectMap = [];

        foreach ($localProjects as $project) {
            $statusLabel = $project->status === 1 ? '(active)' : '(inactive)';
            $displayName = sprintf('%s %s', $project->name, $statusLabel);
            $projectOptions[] = $displayName;
            $projectMap[$displayName] = $project;
        }

        $question = new ChoiceQuestion(
            'Please select a local project:',
            $projectOptions
        );
        $selectedOption = $io->askQuestion($question);

        return $projectMap[$selectedOption] ?? null;
    }

    /**
     * Edit an existing local activity.
     */
    private function editActivity(InputInterface $input, SymfonyStyle $io): int
    {
        $activityIdentifier = $input->getArgument('name');
        if ($activityIdentifier === null || $activityIdentifier === '') {
            $io->error('Activity identifier (alias, ID, or UUID) is required when editing.');
            return Command::FAILURE;
        }

        // Resolve activity using the trait method
        $activity = $this->resolveActivity($activityIdentifier, $io);
        if ($activity === null) {
            $io->error("Activity '{$activityIdentifier}' not found.");
            return Command::FAILURE;
        }

        // Only allow editing local activities
        if ($activity->entityKey->source !== EntitySource::Local) {
            $io->error("Cannot edit activity '{$activity->name}': only local activities can be edited.");
            return Command::FAILURE;
        }

        // Get new values from options (null means don't change)
        $name = $input->getOption('name');
        $description = $input->getOption('description'); // null if not provided
        $aliasOption = $input->getOption('alias'); // null if not provided

        // Handle alias: if explicitly provided (even as empty string), use it
        // For clearing alias, user can pass --alias "" or just --alias with empty value
        $alias = null;
        if ($aliasOption !== null) {
            // If option was provided, use it (empty string means clear alias)
            $alias = trim($aliasOption) === '' ? null : $aliasOption;
        }

        // If no options provided, prompt interactively for values
        if ($name === null && $description === null && $aliasOption === null) {
            $io->writeln(sprintf('Editing activity: %s (%s)', $activity->name, $activity->entityKey->toString()));
            $io->writeln('');

            // Prompt for name
            $nameQuestion = new Question(
                sprintf('Activity name [%s]: ', $activity->name),
                $activity->name
            );
            $name = $io->askQuestion($nameQuestion);
            if ($name === null || trim($name) === '') {
                $name = $activity->name; // Keep existing if empty
            }

            // Prompt for description
            $descriptionQuestion = new Question(
                sprintf('Description [%s]: ', $activity->description ?: '(empty)'),
                $activity->description
            );
            $description = $io->askQuestion($descriptionQuestion);
            if ($description === null) {
                $description = $activity->description; // Keep existing if null
            }

            // Prompt for alias
            $aliasQuestion = new Question(
                sprintf('Alias [%s]: ', $activity->alias ?: '(none)'),
                $activity->alias
            );
            $alias = $io->askQuestion($aliasQuestion);
            // Allow empty string to clear alias
            if ($alias === null) {
                $alias = $activity->alias; // Keep existing if null
            } elseif (trim($alias) === '') {
                $alias = null; // Clear alias if empty string
            }
        }

        try {
            $updatedActivity = $this->activityRepository->update(
                $activity->entityKey->id->getHex(),
                $name,
                $description,
                $alias
            );

            $io->success('Activity updated successfully.');
            $io->writeln(sprintf('Activity: %s', $updatedActivity->name));
            if ($updatedActivity->alias !== null) {
                $io->writeln(sprintf('Alias: %s', $updatedActivity->alias));
            }
            if ($updatedActivity->description !== '') {
                $io->writeln(sprintf('Description: %s', $updatedActivity->description));
            }

            return Command::SUCCESS;
        } catch (InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        } catch (\Exception $e) {
            $io->error('An error occurred: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        // Only provide autocompletion for the 'name' argument when --edit option is present
        if ($input->getOption('edit') && $input->mustSuggestArgumentValuesFor('name')) {
            $this->autocompletion->suggest($input, $suggestions);
        }
    }
}

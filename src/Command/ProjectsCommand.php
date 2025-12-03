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
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Command\Autocompletion\LocalProjectAutocompletion;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Project\ProjectInterface;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;
use Tcrawf\Zebra\Project\ProjectStatus;

class ProjectsCommand extends Command
{
    public function __construct(
        private readonly ProjectRepositoryInterface $projectRepository,
        private readonly LocalProjectAutocompletion $autocompletion
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('projects')
            ->setDescription('Display the list of all projects or add/edit a new local project')
            ->addArgument('name', InputArgument::OPTIONAL, 'Project name or UUID (required when adding/editing)')
            ->addOption('add', 'a', InputOption::VALUE_NONE, 'Add a new local project')
            ->addOption(
                'description',
                'd',
                InputOption::VALUE_REQUIRED,
                'Project description (for add/edit). For add, defaults to empty if not provided. ' .
                'For edit, only updates if provided.'
            )
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'New project name (for edit only)')
            ->addOption(
                'status',
                's',
                InputOption::VALUE_REQUIRED,
                'Project status (0 = inactive, 1 = active, 2 = other). ' .
                'For add, defaults to 1. For edit, only updates if provided.'
            )
            ->addOption('all', null, InputOption::VALUE_NONE, 'Include inactive projects when listing')
            ->addOption('local', 'l', InputOption::VALUE_NONE, 'List only local projects')
            ->addOption('edit', 'e', InputOption::VALUE_NONE, 'Edit an existing local project');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('add')) {
            return $this->addProject($input, $io);
        }

        if ($input->getOption('edit')) {
            return $this->editProject($input, $io);
        }

        return $this->listProjects($input, $io);
    }

    /**
     * List all projects.
     */
    private function listProjects(InputInterface $input, SymfonyStyle $io): int
    {
        // If --all flag is set, pass empty array to get all projects (no status filtering)
        // Otherwise, default behavior is to only show active projects
        $statuses = $input->getOption('all') ? [] : [ProjectStatus::Active];
        $projects = $this->projectRepository->all($statuses);

        // Filter to local projects only if --local option is set
        $localOnly = $input->getOption('local');
        if ($localOnly) {
            $projects = array_filter(
                $projects,
                static fn(ProjectInterface $project) => $project->entityKey->source === EntitySource::Local
            );
        }

        if (empty($projects)) {
            $io->info('No projects found.');
            return Command::SUCCESS;
        }

        foreach ($projects as $project) {
            $projectId = $project->entityKey->toString();
            $displayName = sprintf('%s (%s)', $project->name, $projectId);
            $io->writeln($displayName);
        }

        return Command::SUCCESS;
    }

    /**
     * Add a new local project.
     */
    private function addProject(InputInterface $input, SymfonyStyle $io): int
    {
        $name = $input->getArgument('name');
        if ($name === null || $name === '') {
            $question = new Question('Project name: ');
            $name = $io->askQuestion($question);
            if ($name === null || $name === '') {
                $io->error('Project name is required.');
                return Command::FAILURE;
            }
        }

        $description = $input->getOption('description') ?? '';
        $statusStr = $input->getOption('status') ?? '1';

        // Validate and convert status
        $status = $this->parseStatus($statusStr, $io);
        if ($status === null) {
            return Command::FAILURE;
        }

        try {
            $project = $this->projectRepository->create($name, $description, $status);

            $io->success('Project created successfully.');
            $io->writeln(sprintf('Name: %s', $project->name));
            if ($project->description !== '') {
                $io->writeln(sprintf('Description: %s', $project->description));
            }
            $statusLabel = $project->status === 1 ? 'active' : 'inactive';
            $io->writeln(sprintf('Status: %s', $statusLabel));

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
     * Parse and validate status string.
     * Returns null if invalid.
     */
    private function parseStatus(string $statusStr, SymfonyStyle $io): ?int
    {
        $statusStr = trim($statusStr);

        if ($statusStr === '0' || strtolower($statusStr) === 'inactive') {
            return 0;
        }

        if ($statusStr === '1' || strtolower($statusStr) === 'active') {
            return 1;
        }

        if ($statusStr === '2' || strtolower($statusStr) === 'other') {
            return 2;
        }

        $io->error("Invalid status '{$statusStr}'. Status must be 0 (inactive), 1 (active), or 2 (other).");
        return null;
    }

    /**
     * Edit an existing local project.
     */
    private function editProject(InputInterface $input, SymfonyStyle $io): int
    {
        $projectIdentifier = $input->getArgument('name');
        if ($projectIdentifier === null || $projectIdentifier === '') {
            $io->error('Project name or UUID is required when editing.');
            return Command::FAILURE;
        }

        $project = $this->resolveProject($projectIdentifier, $io);
        if ($project === null) {
            $io->error("Project '{$projectIdentifier}' not found or is not a local project.");
            return Command::FAILURE;
        }

        // Only allow editing local projects
        if ($project->entityKey->source !== EntitySource::Local) {
            $io->error("Cannot edit project '{$project->name}': only local projects can be edited.");
            return Command::FAILURE;
        }

        // Get new values from options (null means don't change)
        $name = $input->getOption('name');
        $description = $input->getOption('description'); // null if not provided
        $statusStr = $input->getOption('status');

        // If no options provided, prompt interactively for values
        if ($name === null && $description === null && $statusStr === null) {
            $io->writeln(sprintf('Editing project: %s (%s)', $project->name, $project->entityKey->toString()));
            $io->writeln('');

            // Prompt for name
            $nameQuestion = new Question(
                sprintf('Project name [%s]: ', $project->name),
                $project->name
            );
            $name = $io->askQuestion($nameQuestion);
            if ($name === null || trim($name) === '') {
                $name = $project->name; // Keep existing if empty
            }

            // Prompt for description
            $descriptionQuestion = new Question(
                sprintf('Description [%s]: ', $project->description ?: '(empty)'),
                $project->description
            );
            $description = $io->askQuestion($descriptionQuestion);
            if ($description === null) {
                $description = $project->description; // Keep existing if null
            }

            // Prompt for status
            $currentStatusLabel = $project->status === 1
                ? 'active'
                : ($project->status === 0 ? 'inactive' : 'other');
            $statusQuestion = new Question(
                sprintf('Status (0=inactive, 1=active, 2=other) [%s]: ', $currentStatusLabel),
                (string) $project->status
            );
            $statusStr = $io->askQuestion($statusQuestion);
            if ($statusStr === null || trim($statusStr) === '') {
                $statusStr = (string) $project->status; // Keep existing if empty
            }
        }

        // Parse status if provided
        $status = null;
        if ($statusStr !== null) {
            $status = $this->parseStatus($statusStr, $io);
            if ($status === null) {
                return Command::FAILURE;
            }
        }

        try {
            $updatedProject = $this->projectRepository->update(
                $project->entityKey,
                $name,
                $description,
                $status
            );

            $io->success('Project updated successfully.');
            $io->writeln(sprintf('Name: %s', $updatedProject->name));
            if ($updatedProject->description !== '') {
                $io->writeln(sprintf('Description: %s', $updatedProject->description));
            }
            $statusLabel = $updatedProject->status === 1
                ? 'active'
                : ($updatedProject->status === 0 ? 'inactive' : 'other');
            $io->writeln(sprintf('Status: %s', $statusLabel));

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
     * Resolve a project by name or UUID.
     *
     * @param string $identifier Project name or UUID
     * @param SymfonyStyle $io
     * @return ProjectInterface|null
     */
    private function resolveProject(string $identifier, SymfonyStyle $io): ?ProjectInterface
    {
        // Try as UUID first
        try {
            $project = $this->projectRepository->get(
                \Tcrawf\Zebra\EntityKey\EntityKey::local($identifier)
            );
            if ($project !== null && $project->entityKey->source === EntitySource::Local) {
                return $project;
            }
        } catch (\Exception $e) {
            // Not a valid UUID, continue to name search
        }

        // Try by name
        $projects = $this->projectRepository->getByNameLike($identifier);

        // Filter to only local projects
        $localProjects = array_filter(
            $projects,
            static fn(ProjectInterface $project) => $project->entityKey->source === EntitySource::Local
        );

        if (empty($localProjects)) {
            return null;
        }

        // If exact match found, return it
        foreach ($localProjects as $project) {
            if (strcasecmp(trim($project->name), trim($identifier)) === 0) {
                return $project;
            }
        }

        // If multiple matches, return the first one (they're already sorted)
        if (count($localProjects) === 1) {
            return reset($localProjects);
        }

        // Multiple matches - show error with suggestions
        $io->error("Multiple local projects found matching '{$identifier}':");
        foreach ($localProjects as $project) {
            $io->writeln(sprintf('  - %s (%s)', $project->name, $project->entityKey->toString()));
        }
        $io->writeln('Please specify the exact project name or UUID.');

        return null;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        // Only provide autocompletion for the 'name' argument when --edit option is present
        if ($input->getOption('edit') && $input->mustSuggestArgumentValuesFor('name')) {
            $this->autocompletion->suggest($input, $suggestions);
        }
    }
}

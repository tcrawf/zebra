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
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Activity\ActivityRepositoryInterface;
use Tcrawf\Zebra\Command\Autocompletion\LocalProjectAutocompletion;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Frame\FrameRepositoryInterface;
use Tcrawf\Zebra\Project\ProjectInterface;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;

class DeleteProjectCommand extends Command
{
    public function __construct(
        private readonly ProjectRepositoryInterface $projectRepository,
        private readonly ActivityRepositoryInterface $activityRepository,
        private readonly FrameRepositoryInterface $frameRepository,
        private readonly LocalProjectAutocompletion $autocompletion
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('delete-project')
            ->setDescription('Delete a local project')
            ->addArgument('project', InputArgument::REQUIRED, 'Project name or UUID')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt')
            ->addOption(
                'cascade',
                'c',
                InputOption::VALUE_NONE,
                'Cascade deletion to activities and frames'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $projectIdentifier = $input->getArgument('project');
        $project = $this->resolveProject($projectIdentifier, $io);

        if ($project === null) {
            $io->error("Project '{$projectIdentifier}' not found or is not a local project.");
            return Command::FAILURE;
        }

        $force = $input->getOption('force');
        $cascade = $input->getOption('cascade');

        // Check if project has activities
        if (!$cascade && $this->projectRepository->hasActivities($project->entityKey)) {
            $activities = $this->projectRepository->getActivities($project->entityKey);
            $io->error(
                "Cannot delete project '{$project->name}': project has " . count($activities) . ' activity(ies).'
            );
            $io->writeln('');
            $io->writeln('Activities preventing deletion:');
            foreach ($activities as $activity) {
                $activityInfo = sprintf(
                    '  - %s (%s)',
                    $activity->name,
                    $activity->entityKey->toString()
                );
                if ($activity->alias !== null) {
                    $activityInfo .= " [alias: {$activity->alias}]";
                }
                $io->writeln($activityInfo);
            }
            $io->writeln('');
            $io->writeln('Use --cascade to cascade deletion to activities and frames.');
            return Command::FAILURE;
        }

        // Prompt for confirmation unless --force is set
        if (!$force) {
            $confirmationMessage = sprintf(
                'Are you sure you want to delete project "%s" (%s)?',
                $project->name,
                $project->entityKey->toString()
            );
            if ($cascade) {
                $confirmationMessage .= ' This will also delete all associated activities and frames.';
            }

            $confirmed = $io->confirm($confirmationMessage, false);

            if (!$confirmed) {
                $io->info('Project deletion cancelled.');
                return Command::SUCCESS;
            }
        }

        try {
            if ($cascade) {
                // Track deleted activities and frames
                $deletedActivities = [];
                $deletedFrames = [];

                // Use force delete with cascade
                $this->projectRepository->forceDelete(
                    $project->entityKey,
                    function ($activity) use (&$deletedActivities, &$deletedFrames): void {
                        // Delete the activity (force delete will handle frames)
                        $this->activityRepository->forceDelete(
                            $activity->entityKey->id->getHex(),
                            function ($frame) use (&$deletedFrames): void {
                                $deletedFrames[] = $frame;
                                $this->frameRepository->remove($frame->uuid);
                            }
                        );
                        $deletedActivities[] = $activity;
                    },
                    function ($activity): void {
                        // This parameter is not used - frames are deleted via activity
                        // forceDelete above
                    }
                );

                $io->success("Project '{$project->name}' deleted successfully.");
                if (!empty($deletedActivities)) {
                    $io->writeln('');
                    $io->writeln('Deleted activities:');
                    foreach ($deletedActivities as $deletedActivity) {
                        $activityInfo = sprintf(
                            '  - %s (%s)',
                            $deletedActivity->name,
                            $deletedActivity->entityKey->toString()
                        );
                        if ($deletedActivity->alias !== null) {
                            $alias = $deletedActivity->alias;
                            $activityInfo .= " [alias: {$alias}]";
                        }
                        $io->writeln($activityInfo);
                    }
                }
                if (!empty($deletedFrames)) {
                    $io->writeln('');
                    $io->writeln('Deleted frames:');
                    foreach ($deletedFrames as $frame) {
                        $frameInfo = sprintf(
                            '  - UUID: %s, Start: %s',
                            $frame->uuid,
                            $frame->startTime->toDateTimeString()
                        );
                        if ($frame->stopTime !== null) {
                            $frameInfo .= sprintf(', Stop: %s', $frame->stopTime->toDateTimeString());
                        } else {
                            $frameInfo .= ' (active)';
                        }
                        $io->writeln($frameInfo);
                    }
                }
            } else {
                $this->projectRepository->delete($project->entityKey);
                $io->success("Project '{$project->name}' deleted successfully.");
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
                \Tcrawf\Zebra\EntityKey\EntityKey::local(
                    $identifier
                )
            );
            if (
                $project !== null
                && $project->entityKey->source === EntitySource::Local
            ) {
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
        if ($input->mustSuggestArgumentValuesFor('project')) {
            $this->autocompletion->suggest($input, $suggestions);
        }
    }
}

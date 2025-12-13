<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command\Task;

use Carbon\Carbon;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Activity\ActivityRepositoryInterface;
use Tcrawf\Zebra\Command\Trait\ActivityResolutionTrait;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;
use Tcrawf\Zebra\Task\TaskFactory;
use Tcrawf\Zebra\Task\TaskRepositoryInterface;
use Tcrawf\Zebra\Task\TaskStatus;

class CreateCommand extends Command
{
    use ActivityResolutionTrait;

    public function __construct(
        private readonly TaskRepositoryInterface $taskRepository,
        private readonly ActivityRepositoryInterface $activityRepository,
        private readonly ProjectRepositoryInterface $projectRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('task:create')
            ->setAliases(['task:add'])
            ->setDescription('Create a new task')
            ->addArgument(
                'summary',
                InputArgument::REQUIRED,
                'Task summary (description)'
            )
            ->addOption(
                'activity',
                'a',
                InputOption::VALUE_REQUIRED,
                'Activity alias or ID to associate with the task'
            )
            ->addOption(
                'due',
                'd',
                InputOption::VALUE_REQUIRED,
                'Due date (ISO 8601 format)'
            )
            ->addOption(
                'issue-tag',
                'i',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Issue tag (can be specified multiple times)'
            )
            ->addOption(
                'status',
                's',
                InputOption::VALUE_REQUIRED,
                'Initial status (open, in-progress). Defaults to open.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $summary = $input->getArgument('summary');
        if (trim($summary) === '') {
            $io->error('Task summary cannot be empty');
            return Command::FAILURE;
        }

        // Resolve activity if provided
        $activity = null;
        $activityIdentifier = $input->getOption('activity');
        if ($activityIdentifier !== null) {
            $activity = $this->resolveActivity($activityIdentifier, $io);
            if ($activity === null) {
                $io->error("Activity '{$activityIdentifier}' not found");
                return Command::FAILURE;
            }
        }

        // Parse due date
        $dueAt = null;
        $dueStr = $input->getOption('due');
        if ($dueStr !== null) {
            try {
                $dueAt = Carbon::parse($dueStr);
            } catch (\Exception $e) {
                $io->error("Invalid due date format: {$dueStr}. Use ISO 8601 format.");
                return Command::FAILURE;
            }
        }

        // Parse issue tags
        $issueTags = $input->getOption('issue-tag');
        $issueTags = !empty($issueTags) ? $issueTags : [];

        // Parse status
        $status = TaskStatus::Open;
        $statusStr = $input->getOption('status');
        if ($statusStr !== null) {
            try {
                $status = TaskStatus::from($statusStr);
                if ($status === TaskStatus::Complete) {
                    $io->error('Cannot create a task with complete status. Use task:complete to complete a task.');
                    return Command::FAILURE;
                }
            } catch (\ValueError $e) {
                $io->error("Invalid status '{$statusStr}'. Valid values: open, in-progress");
                return Command::FAILURE;
            }
        }

        try {
            $task = TaskFactory::create(
                $summary,
                null, // createdAt - will default to now
                $dueAt,
                null, // completedAt
                $activity,
                $issueTags,
                $status,
                '' // completionNote
            );

            $this->taskRepository->save($task);

            $io->success('Task created successfully');
            $io->writeln(sprintf('UUID: %s', $task->uuid));
            $io->writeln(sprintf('Summary: %s', $task->summary));
            $io->writeln(sprintf('Status: %s', $task->status->value));
            if (!empty($task->issueTags)) {
                $io->writeln(sprintf('Issue tags: %s', implode(', ', $task->issueTags)));
            }
            if ($task->activity !== null) {
                $io->writeln(sprintf('Activity: %s', $task->activity->name));
            }
            if ($task->dueAt !== null) {
                $io->writeln(sprintf('Due: %s', $task->dueAt->toDateTimeString()));
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('An error occurred: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Get activity repository instance.
     */
    protected function getActivityRepository(): ActivityRepositoryInterface
    {
        return $this->activityRepository;
    }

    /**
     * Get project repository instance.
     */
    protected function getProjectRepository(): ProjectRepositoryInterface
    {
        return $this->projectRepository;
    }
}

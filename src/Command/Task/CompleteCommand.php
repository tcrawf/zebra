<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command\Task;

use Carbon\Carbon;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Command\Autocompletion\TaskAutocompletion;
use Tcrawf\Zebra\Task\TaskFactory;
use Tcrawf\Zebra\Task\TaskRepositoryInterface;
use Tcrawf\Zebra\Task\TaskStatus;

class CompleteCommand extends Command
{
    public function __construct(
        private readonly TaskRepositoryInterface $taskRepository,
        private readonly TaskAutocompletion $autocompletion
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('task:complete')
            ->setDescription('Mark a task as completed')
            ->addArgument(
                'uuid',
                InputArgument::REQUIRED,
                'Task UUID'
            )
            ->addOption(
                'note',
                'n',
                InputOption::VALUE_REQUIRED,
                'Completion note'
            )
            ->addOption(
                'at',
                null,
                InputOption::VALUE_REQUIRED,
                'Completion time (ISO 8601 format, defaults to current time)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $uuid = $input->getArgument('uuid');

        // Get existing task
        $task = $this->taskRepository->get($uuid);
        if ($task === null) {
            $io->error("Task with UUID '{$uuid}' not found");
            return Command::FAILURE;
        }

        // Check if already complete
        if ($task->status === TaskStatus::Complete) {
            $io->warning('Task is already complete');
            return Command::SUCCESS;
        }

        // Parse completion time
        $completedAt = Carbon::now();
        $atStr = $input->getOption('at');
        if ($atStr !== null) {
            try {
                $completedAt = Carbon::parse($atStr);
            } catch (\Exception $e) {
                $io->error("Invalid completion time format: {$atStr}. Use ISO 8601 format.");
                return Command::FAILURE;
            }
        }

        // Get completion note
        $completionNote = $input->getOption('note') ?? '';

        try {
            // Create completed task
            $completedTask = TaskFactory::create(
                $task->summary,
                $task->createdAt,
                $task->dueAt,
                $completedAt,
                $task->activity,
                $task->issueTags,
                TaskStatus::Complete,
                $completionNote,
                \Tcrawf\Zebra\Uuid\Uuid::fromHex($task->uuid)
            );

            $this->taskRepository->update($completedTask);

            $io->success('Task completed successfully');
            $io->writeln(sprintf('UUID: %s', $completedTask->uuid));
            $io->writeln(sprintf('Summary: %s', $completedTask->summary));
            $io->writeln(sprintf('Completed at: %s', $completedTask->completedAt->toDateTimeString()));
            if ($completedTask->completionNote !== '') {
                $io->writeln(sprintf('Completion note: %s', $completedTask->completionNote));
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('An error occurred: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('uuid')) {
            $this->autocompletion->suggest($input, $suggestions);
        }
    }
}

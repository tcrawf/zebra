<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command\Task;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Command\Autocompletion\TaskAutocompletion;
use Tcrawf\Zebra\Task\TaskRepositoryInterface;

class DeleteCommand extends Command
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
            ->setName('task:delete')
            ->setAliases(['task:remove'])
            ->setDescription('Delete a task')
            ->addArgument(
                'uuid',
                InputArgument::REQUIRED,
                'Task UUID'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $uuid = $input->getArgument('uuid');

        // Check if task exists
        $task = $this->taskRepository->get($uuid);
        if ($task === null) {
            $io->error("Task with UUID '{$uuid}' not found");
            return Command::FAILURE;
        }

        try {
            $this->taskRepository->remove($uuid);
            $io->success(sprintf('Task "%s" deleted successfully', $task->summary));
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

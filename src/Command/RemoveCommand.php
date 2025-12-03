<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Command\Autocompletion\FrameAutocompletion;
use Tcrawf\Zebra\Frame\FrameInterface;
use Tcrawf\Zebra\Frame\FrameRepositoryInterface;

class RemoveCommand extends Command
{
    public function __construct(
        private readonly FrameRepositoryInterface $frameRepository,
        private readonly FrameAutocompletion $autocompletion
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('remove')
            ->setDescription('Remove a frame')
            ->addArgument('frame', InputArgument::REQUIRED, 'Frame UUID or index (-1 for last, -2 for second-to-last)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, "Don't ask for confirmation");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $frameIdentifier = $input->getArgument('frame');
        $frame = $this->resolveFrame($frameIdentifier);

        if ($frame === null) {
            $io->writeln("<fg=red>Frame '{$frameIdentifier}' not found</fg=red>");
            return Command::FAILURE;
        }

        $force = $input->getOption('force');

        if (!$force) {
            $confirmed = $io->confirm(
                sprintf(
                    'Are you sure you want to remove frame %s (Activity: %s, Start: %s)?',
                    $frame->uuid,
                    $frame->activity->name,
                    $frame->startTime->toDateTimeString()
                ),
                false
            );

            if (!$confirmed) {
                $io->info('Frame removal cancelled.');
                return Command::SUCCESS;
            }
        }

        try {
            $this->frameRepository->remove($frame->uuid);
            $io->writeln('<info>Frame removed successfully.</info>');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->writeln('<fg=red>An error occurred: ' . $e->getMessage() . '</fg=red>');
            return Command::FAILURE;
        }
    }

    /**
     * Resolve a frame by UUID or negative index.
     *
     * @param string $identifier Frame UUID or negative index (-1, -2, etc.)
     * @return FrameInterface|null
     */
    private function resolveFrame(string $identifier): ?FrameInterface
    {
        // Check if it's a negative index
        if (preg_match('/^-?\d+$/', $identifier)) {
            $index = (int) $identifier;
            $allFrames = $this->frameRepository->all();

            // Sort by start time, descending (most recent first)
            usort($allFrames, static fn($a, $b) => $b->startTime->timestamp <=> $a->startTime->timestamp);

            // Convert negative index to positive array index
            if ($index < 0) {
                $arrayIndex = abs($index) - 1;
            } else {
                $arrayIndex = $index - 1;
            }

            if ($arrayIndex >= 0 && $arrayIndex < count($allFrames)) {
                return $allFrames[$arrayIndex];
            }

            return null;
        }

        // Try as UUID
        return $this->frameRepository->get($identifier);
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('frame')) {
            $this->autocompletion->suggest($input, $suggestions);
        }
    }
}

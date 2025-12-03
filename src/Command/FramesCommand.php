<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Frame\FrameRepositoryInterface;

class FramesCommand extends Command
{
    public function __construct(
        private readonly FrameRepositoryInterface $frameRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('frames')
            ->setDescription('Display the list of all frame IDs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $frames = $this->frameRepository->all();

        if (empty($frames)) {
            $io->info('No frames found.');
            return Command::SUCCESS;
        }

        // Sort by start time, descending (most recent first)
        usort($frames, static fn($a, $b) => $b->startTime->timestamp <=> $a->startTime->timestamp);

        foreach ($frames as $frame) {
            $io->writeln($frame->uuid);
        }

        return Command::SUCCESS;
    }
}

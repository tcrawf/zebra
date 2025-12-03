<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Exception\NoFrameStartedException;
use Tcrawf\Zebra\Track\TrackInterface;

class CancelCommand extends Command
{
    public function __construct(
        private readonly TrackInterface $track
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('cancel')
            ->setDescription('Cancel the last call to the start command. The time will not be recorded.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $frame = $this->track->cancel();

            $io->writeln('<info>Frame cancelled successfully</info>');
            $io->writeln("<info>UUID: {$frame->uuid}</info>");
            $io->writeln("<info>Activity: {$frame->activity->name}</info>");

            return Command::SUCCESS;
        } catch (NoFrameStartedException $e) {
            $io->writeln("<fg=red>{$e->getMessage()}</fg=red>");
            return Command::FAILURE;
        } catch (\Exception $e) {
            $io->writeln('<fg=red>An error occurred: ' . $e->getMessage() . '</fg=red>');
            return Command::FAILURE;
        }
    }
}

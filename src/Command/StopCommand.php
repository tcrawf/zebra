<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command;

use Carbon\Carbon;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Exception\InvalidTimeException;
use Tcrawf\Zebra\Exception\NoFrameStartedException;
use Tcrawf\Zebra\Track\TrackInterface;

class StopCommand extends Command
{
    public function __construct(
        private readonly TrackInterface $track
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('stop')
            ->setDescription('Stop monitoring time for the current project')
            ->addOption('at', null, InputOption::VALUE_OPTIONAL, 'Stop time (ISO 8601 format)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $stopAt = null;
            if ($input->getOption('at') !== null) {
                $stopAt = Carbon::parse($input->getOption('at'));
            }

            $frame = $this->track->stop($stopAt);

            $duration = $frame->getDuration();
            $durationFormatted = $duration !== null
                ? sprintf('%02d:%02d:%02d', floor($duration / 3600), floor(($duration % 3600) / 60), $duration % 60)
                : 'N/A';

            $io->writeln('<info>Frame stopped successfully</info>');
            $io->writeln("<info>UUID: {$frame->uuid}</info>");
            $io->writeln("<info>Activity: {$frame->activity->name}</info>");
            $io->writeln("<info>Duration: {$durationFormatted}</info>");

            $description = trim($frame->description);
            if ($description !== '') {
                $io->writeln("<info>Description: {$description}</info>");
            }

            return Command::SUCCESS;
        } catch (NoFrameStartedException $e) {
            $io->writeln("<fg=red>{$e->getMessage()}</fg=red>");
            return Command::FAILURE;
        } catch (InvalidTimeException $e) {
            $io->writeln("<fg=red>{$e->getMessage()}</fg=red>");
            return Command::FAILURE;
        } catch (\Exception $e) {
            $io->writeln('<fg=red>An error occurred: ' . $e->getMessage() . '</fg=red>');
            return Command::FAILURE;
        }
    }
}

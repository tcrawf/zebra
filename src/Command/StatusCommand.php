<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Command\Trait\ProjectNameHelperTrait;
use Tcrawf\Zebra\Frame\FrameInterface;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;
use Tcrawf\Zebra\Track\TrackInterface;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;

class StatusCommand extends Command
{
    use ProjectNameHelperTrait;

    public function __construct(
        private readonly TrackInterface $track,
        private readonly TimezoneFormatter $timezoneFormatter,
        private readonly ProjectRepositoryInterface $projectRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('status')
            ->setDescription('Display when the current project was started and the time spent since')
            ->addOption('project', 'p', InputOption::VALUE_NONE, 'Only output project name')
            ->addOption('activity', 'a', InputOption::VALUE_NONE, 'Only output activity name')
            ->addOption('elapsed', 'e', InputOption::VALUE_NONE, 'Only show elapsed time');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->track->isStarted()) {
            $io->info('No project started.');
            return Command::SUCCESS;
        }

        $frame = $this->track->getCurrent();
        if ($frame === null) {
            $io->info('No project started.');
            return Command::SUCCESS;
        }

        $projectOnly = $input->getOption('project');
        $activityOnly = $input->getOption('activity');
        $elapsedOnly = $input->getOption('elapsed');

        if ($projectOnly) {
            $io->writeln($this->getProjectName($frame));
            return Command::SUCCESS;
        }

        if ($activityOnly) {
            $io->writeln($frame->activity->name);
            return Command::SUCCESS;
        }

        if ($elapsedOnly) {
            $elapsed = $this->getElapsedTime($frame);
            $io->writeln($elapsed);
            return Command::SUCCESS;
        }

        // Full status output
        $startTimeLocal = $this->timezoneFormatter->toLocal($frame->startTime);
        $elapsed = $this->getElapsedTime($frame);

        $description = trim($frame->description);
        if ($description !== '') {
            $io->writeln(sprintf(
                'Project %s [%s] started %s (%s) - %s',
                $this->getProjectName($frame),
                $frame->activity->name,
                $elapsed,
                $startTimeLocal->toDateTimeString(),
                $description
            ));
        } else {
            $io->writeln(sprintf(
                'Project %s [%s] started %s (%s)',
                $this->getProjectName($frame),
                $frame->activity->name,
                $elapsed,
                $startTimeLocal->toDateTimeString()
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * Get project repository instance.
     */
    protected function getProjectRepository(): ProjectRepositoryInterface
    {
        return $this->projectRepository;
    }

    /**
     * Override fallback behavior for StatusCommand.
     * StatusCommand uses activity name as fallback instead of "Project {key}".
     */
    protected function getProjectNameFallback(FrameInterface $frame, $projectEntityKey): string
    {
        return $frame->activity->name;
    }

    private function getElapsedTime(FrameInterface $frame): string
    {
        $now = \Carbon\Carbon::now()->utc();
        $startTime = $frame->startTime;
        $elapsedSeconds = $now->diffInSeconds($startTime);

        $hours = floor($elapsedSeconds / 3600);
        $minutes = floor(($elapsedSeconds % 3600) / 60);
        $seconds = $elapsedSeconds % 60;

        if ($hours > 0) {
            return sprintf('%dh %dm %ds', $hours, $minutes, $seconds);
        } elseif ($minutes > 0) {
            return sprintf('%dm %ds', $minutes, $seconds);
        } else {
            return sprintf('%ds', $seconds);
        }
    }
}

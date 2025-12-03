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
use Tcrawf\Zebra\Command\Autocompletion\LocalActivityAutocompletion;
use Tcrawf\Zebra\Command\Trait\ActivityResolutionTrait;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Frame\FrameRepositoryInterface;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;

class DeleteActivityCommand extends Command
{
    use ActivityResolutionTrait;

    public function __construct(
        private readonly ActivityRepositoryInterface $activityRepository,
        private readonly ProjectRepositoryInterface $projectRepository,
        private readonly FrameRepositoryInterface $frameRepository,
        private readonly LocalActivityAutocompletion $autocompletion
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('delete-activity')
            ->setDescription('Delete a local activity')
            ->addArgument('activity', InputArgument::REQUIRED, 'Activity alias, ID, or UUID')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt')
            ->addOption('cascade', 'c', InputOption::VALUE_NONE, 'Cascade deletion to frames');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $activityIdentifier = $input->getArgument('activity');
        $activity = $this->resolveActivity($activityIdentifier, $io);

        if ($activity === null) {
            $io->error("Activity '{$activityIdentifier}' not found or is not a local activity.");
            return Command::FAILURE;
        }

        // Only allow deletion of local activities
        if ($activity->entityKey->source !== EntitySource::Local) {
            $io->error("Cannot delete activity '{$activity->name}': only local activities can be deleted.");
            return Command::FAILURE;
        }

        $force = $input->getOption('force');
        $cascade = $input->getOption('cascade');

        // Check if activity has frames
        if (!$cascade && $this->activityRepository->hasFrames($activity->entityKey->id->getHex())) {
            $activityUuid = $activity->entityKey->id->getHex();
            $frames = $this->activityRepository->getFrames($activityUuid);
            $frameCount = count($frames);
            $io->error(
                "Cannot delete activity '{$activity->name}': activity has {$frameCount} frame(s) referencing it."
            );
            $io->writeln('');
            $io->writeln('Frames preventing deletion:');
            foreach ($frames as $frame) {
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
            $io->writeln('');
            $io->writeln('Use --cascade to cascade deletion to frames.');
            return Command::FAILURE;
        }

        // Prompt for confirmation unless --force is set
        if (!$force) {
            $confirmationMessage = sprintf(
                'Are you sure you want to delete activity "%s" (%s)?',
                $activity->name,
                $activity->entityKey->toString()
            );
            if ($cascade) {
                $confirmationMessage .= ' This will also delete all associated frames.';
            }

            $confirmed = $io->confirm($confirmationMessage, false);

            if (!$confirmed) {
                $io->info('Activity deletion cancelled.');
                return Command::SUCCESS;
            }
        }

        try {
            if ($cascade) {
                // Track deleted frames
                $deletedFrames = [];

                // Use force delete with cascade
                $this->activityRepository->forceDelete(
                    $activity->entityKey->id->getHex(),
                    function ($frame) use (&$deletedFrames): void {
                        $deletedFrames[] = $frame;
                        $this->frameRepository->remove($frame->uuid);
                    }
                );

                $io->success("Activity '{$activity->name}' deleted successfully.");
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
                $this->activityRepository->delete($activity->entityKey->id->getHex());
                $io->success("Activity '{$activity->name}' deleted successfully.");
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

    protected function getActivityRepository(): ActivityRepositoryInterface
    {
        return $this->activityRepository;
    }

    protected function getProjectRepository(): ProjectRepositoryInterface
    {
        return $this->projectRepository;
    }

    protected function shouldIncludeInactiveProjects(): bool
    {
        return false;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('activity')) {
            $this->autocompletion->suggest($input, $suggestions);
        }
    }
}

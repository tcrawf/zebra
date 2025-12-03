<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command;

use Carbon\Carbon;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Command\Autocompletion\FrameAutocompletion;
use Tcrawf\Zebra\Exception\FrameAlreadyStartedException;
use Tcrawf\Zebra\Exception\InvalidTimeException;
use Tcrawf\Zebra\Frame\FrameInterface;
use Tcrawf\Zebra\Frame\FrameRepositoryInterface;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;
use Tcrawf\Zebra\Track\TrackInterface;

class RestartCommand extends Command
{
    public function __construct(
        private readonly TrackInterface $track,
        private readonly FrameRepositoryInterface $frameRepository,
        private readonly FrameAutocompletion $autocompletion,
        private readonly TimezoneFormatter $timezoneFormatter
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('restart')
            ->setDescription('Restart monitoring time for a previously stopped project')
            ->addArgument(
                'frame',
                InputArgument::OPTIONAL,
                'Frame UUID or index (-1 for last, -2 for second-to-last)',
                '-1'
            )
            ->addOption(
                'frame',
                'f',
                InputOption::VALUE_OPTIONAL,
                'Frame UUID to restart, or omit value to select from today\'s frames'
            )
            ->addOption('at', null, InputOption::VALUE_OPTIONAL, 'Start time (ISO 8601 format)')
            ->addOption('no-gap', null, InputOption::VALUE_NONE, 'Start immediately after previous frame ends')
            ->addOption('stop', null, InputOption::VALUE_NONE, 'Stop current frame before restarting')
            ->addOption('no-stop', null, InputOption::VALUE_NONE, 'Do not stop current frame before restarting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            // Check if --frame option is provided
            $frameOption = $input->getOption('frame');
            $frameIdentifier = null;
            $frame = null;

            // Check if --frame option was provided (even without a value)
            if ($input->hasParameterOption('--frame') || $input->hasParameterOption('-f')) {
                // --frame option was provided
                if ($frameOption === null || $frameOption === '') {
                    // --frame without value: check if frame is already started before prompting
                    $shouldStop = $input->getOption('stop');
                    $shouldNotStop = $input->getOption('no-stop');

                    if ($this->track->isStarted()) {
                        if ($shouldStop) {
                            $this->track->stop();
                        } elseif (!$shouldNotStop) {
                            // Default behavior: show error if frame is already started
                            $currentFrame = $this->frameRepository->getCurrent();
                            if ($currentFrame !== null) {
                                $startTimeLocal = $this->timezoneFormatter->toLocal($currentFrame->startTime);
                                $message = sprintf(
                                    'A frame is already started. Stop or cancel the current frame before ' .
                                    'starting a new one. Current frame: UUID=%s, Start=%s, Activity=%s, Role=%s',
                                    $currentFrame->uuid,
                                    $startTimeLocal->toIso8601String(),
                                    $currentFrame->activity->name,
                                    $currentFrame->isIndividual
                                        ? 'Individual'
                                        : ($currentFrame->role !== null ? $currentFrame->role->name : 'No role')
                                );
                                $io->writeln("<fg=red>{$message}</fg=red>");
                                return Command::FAILURE;
                            }
                        }
                    }

                    // --frame without value: show interactive list from today
                    $frame = $this->selectFrameFromToday($io, $input);
                    if ($frame === null) {
                        return Command::FAILURE;
                    }
                } else {
                    // --frame with value: use the provided UUID
                    $frameIdentifier = $frameOption;
                    $frame = $this->resolveFrame($frameIdentifier);
                }
            } else {
                // No --frame option, use argument (backward compatibility)
                $frameIdentifier = $input->getArgument('frame');
                $frame = $this->resolveFrame($frameIdentifier);
            }

            if ($frame === null) {
                $displayIdentifier = $frameIdentifier ?? 'unknown';
                $io->writeln("<fg=red>Frame '{$displayIdentifier}' not found</fg=red>");
                return Command::FAILURE;
            }

            // Handle stop option (for cases where frame was already resolved)
            $shouldStop = $input->getOption('stop');
            $shouldNotStop = $input->getOption('no-stop');

            if ($shouldStop && $this->track->isStarted()) {
                $this->track->stop();
            } elseif ($shouldNotStop !== true && $this->track->isStarted()) {
                // Default behavior: don't stop, but check if we can start
                // The track service will throw FrameAlreadyStartedException if needed
            }

            $startAt = null;
            if ($input->getOption('at') !== null) {
                $startAt = Carbon::parse($input->getOption('at'));
            }

            $gap = !$input->getOption('no-gap');

            // Restart with the same activity, description, isIndividual, and role
            $restartedFrame = $this->track->start(
                $frame->activity,
                $frame->description,
                $startAt,
                $gap,
                $frame->isIndividual,
                $frame->role
            );

            $io->writeln('<info>Frame restarted successfully</info>');
            $io->writeln("<info>UUID: {$restartedFrame->uuid}</info>");
            $io->writeln("<info>Activity: {$restartedFrame->activity->name}</info>");
            $io->writeln("<info>Description: {$restartedFrame->description}</info>");

            return Command::SUCCESS;
        } catch (FrameAlreadyStartedException $e) {
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

            // Filter out active frames and sort by start time descending
            $completedFrames = array_filter(
                $allFrames,
                static fn($frame) => $frame->stopTime !== null
            );

            // Sort by start time, descending (most recent first)
            usort($completedFrames, static function ($a, $b) {
                return $b->startTime->timestamp <=> $a->startTime->timestamp;
            });

            // Convert negative index to positive array index
            if ($index < 0) {
                $arrayIndex = abs($index) - 1;
            } else {
                $arrayIndex = $index - 1;
            }

            if ($arrayIndex >= 0 && $arrayIndex < count($completedFrames)) {
                // array_values needed to reindex after array_filter preserves keys
                // @phpstan-ignore-next-line arrayValues.list
                $completedFramesArray = array_values($completedFrames);
                return $completedFramesArray[$arrayIndex];
            }

            return null;
        }

        // Try as UUID
        return $this->frameRepository->get($identifier);
    }

    /**
     * Select a frame interactively from today's frames.
     *
     * @param SymfonyStyle $io
     * @param InputInterface $input
     * @return FrameInterface|null
     */
    private function selectFrameFromToday(SymfonyStyle $io, InputInterface $input): ?FrameInterface
    {
        // Check if input is interactive
        if (!$input->isInteractive()) {
            $io->error('Frame selection requires interactive mode. Please provide frame UUID with --frame option.');
            return null;
        }

        // Get frames from today
        $now = Carbon::now();
        $todayStart = $now->copy()->startOfDay()->utc();
        $todayEnd = $now->copy()->endOfDay()->utc();

        $todayFrames = $this->frameRepository->getByDateRange($todayStart, $todayEnd);

        if (empty($todayFrames)) {
            $io->writeln('<fg=red>No frames found for today.</fg=red>');
            return null;
        }

        // Sort by start time, descending (most recent first)
        usort($todayFrames, static function ($a, $b) {
            return $b->startTime->timestamp <=> $a->startTime->timestamp;
        });

        // Build options for choice question
        $frameOptions = [];
        $frameMap = [];

        foreach ($todayFrames as $frame) {
            $startLocal = $this->timezoneFormatter->toLocal($frame->startTime);
            $stopLocal = $frame->stopTime !== null
                ? $this->timezoneFormatter->toLocal($frame->stopTime)
                : null;

            $timeFrame = sprintf(
                '%s - %s',
                $startLocal->format('H:i'),
                $stopLocal?->format('H:i') ?? '--:--'
            );

            $duration = $frame->getDuration();
            $durationFormatted = $duration !== null
                ? $this->formatDuration($duration)
                : 'N/A';

            $activityDisplay = $frame->activity->alias ?? $frame->activity->name;
            $description = $frame->description !== '' ? $frame->description : '(no description)';

            $displayName = sprintf(
                '%s | %s | %s | %s | %s',
                $frame->uuid,
                $timeFrame,
                $durationFormatted,
                $activityDisplay,
                $description
            );

            $frameOptions[] = $displayName;
            $frameMap[$displayName] = $frame;
        }

        $question = new ChoiceQuestion(
            'Please select a frame to restart:',
            $frameOptions
        );
        $question->setErrorMessage('Invalid frame selection: %s');

        try {
            $selectedOption = $io->askQuestion($question);
            return $frameMap[$selectedOption] ?? null;
        } catch (\Exception $e) {
            $io->error('Failed to read input: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Format duration in HH:MM:SS format.
     *
     * @param int $seconds
     * @return string
     */
    private function formatDuration(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('frame')) {
            $this->autocompletion->suggest($input, $suggestions);
        }

        // Handle both long and short option names
        if ($input->mustSuggestOptionValuesFor('frame') || $input->mustSuggestOptionValuesFor('f')) {
            $this->autocompletion->suggest($input, $suggestions);
        }
    }
}

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
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Activity\ActivityRepositoryInterface;
use Tcrawf\Zebra\Command\Autocompletion\ActivityOrProjectAutocompletion;
use Tcrawf\Zebra\Command\Trait\ActivityResolutionTrait;
use Tcrawf\Zebra\Command\Trait\ArgumentParsingTrait;
use Tcrawf\Zebra\Command\Trait\RoleResolutionTrait;
use Tcrawf\Zebra\Config\ConfigFileStorageInterface;
use Tcrawf\Zebra\Exception\FrameAlreadyStartedException;
use Tcrawf\Zebra\Exception\InvalidTimeException;
use Tcrawf\Zebra\Frame\FrameFormatter;
use Tcrawf\Zebra\Frame\FrameRepositoryInterface;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;
use Tcrawf\Zebra\Track\TrackInterface;
use Tcrawf\Zebra\User\UserRepositoryInterface;

class StartCommand extends Command
{
    use ArgumentParsingTrait;
    use ActivityResolutionTrait;
    use RoleResolutionTrait;

    public function __construct(
        private readonly TrackInterface $track,
        private readonly ActivityRepositoryInterface $activityRepository,
        private readonly UserRepositoryInterface $userRepository,
        private readonly FrameRepositoryInterface $frameRepository,
        private readonly ConfigFileStorageInterface $configStorage,
        private readonly ProjectRepositoryInterface $projectRepository,
        private readonly ActivityOrProjectAutocompletion $autocompletion
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('start')
            ->setDescription('Start tracking a new frame')
            ->addArgument(
                'activity',
                InputArgument::REQUIRED | InputArgument::IS_ARRAY,
                'Activity alias or ID (use +description or +"description text" for frame description)'
            )
            ->addOption(
                'description',
                'd',
                InputOption::VALUE_OPTIONAL,
                'Frame description (alternative: use +description or +"description text" syntax)'
            )
            ->addOption('role', 'r', InputOption::VALUE_OPTIONAL, 'Role ID or name')
            ->addOption(
                'individual',
                null,
                InputOption::VALUE_NONE,
                'Mark frame as individual action (does not require a role)'
            )
            ->addOption('no-gap', null, InputOption::VALUE_NONE, 'Start immediately after previous frame ends')
            ->addOption('at', null, InputOption::VALUE_OPTIONAL, 'Start time (ISO 8601 format)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Check if a frame is already started before prompting for activity
        $currentFrame = $this->track->getCurrent();
        if ($currentFrame !== null) {
            // Convert start time to system timezone for display
            $startTimeLocal = FrameFormatter::formatStart($currentFrame);
            $message = sprintf(
                'A frame is already started. Stop or cancel the current frame before starting a new one. ' .
                'Current frame: UUID=%s, Start=%s, Activity=%s, Role=%s',
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

        // Parse arguments to handle +description syntax
        $parsed = $this->parseActivityArguments($input);
        $activityIdentifier = $parsed['activity'];
        $descriptionFromPlus = $parsed['description'];

        // Use description from + syntax if provided, otherwise use option
        $description = $descriptionFromPlus ?? $input->getOption('description');

        // If no activity found, try to find last activity for issue keys in description
        $activity = null;
        if ($activityIdentifier === null) {
            $issueKeys = $this->extractIssueKeys($description ?? '');
            if (!empty($issueKeys)) {
                $activity = $this->frameRepository->getLastActivityForIssueKeys($issueKeys);
                if ($activity !== null) {
                    // Found last activity for these issue keys, use it automatically
                    // No need to resolve further
                }
            }

            // If still no activity found, show search/menu
            if ($activity === null) {
                // Prompt user to search for activity (only in interactive mode)
                if ($input->isInteractive()) {
                    $searchTerm = $io->ask('No activity specified. Search for activity', '');
                    if (empty($searchTerm)) {
                        $io->writeln('<fg=red>Activity is required</fg=red>');
                        return Command::FAILURE;
                    }
                    $activityIdentifier = $searchTerm;
                } else {
                    $io->writeln('<fg=red>Activity is required</fg=red>');
                    return Command::FAILURE;
                }
            }
        }

        // Resolve activity if we don't have one yet (either from identifier or from last activity lookup)
        if ($activity === null) {
            $activity = $this->resolveActivity($activityIdentifier, $io);
            if ($activity === null) {
                $io->writeln("<fg=red>Activity '{$activityIdentifier}' not found</fg=red>");
                return Command::FAILURE;
            }
        }

        try {
            $gap = !$input->getOption('no-gap');
            $startAt = null;
            if ($input->getOption('at') !== null) {
                $startAt = Carbon::parse($input->getOption('at'));
            }

            $isIndividual = $input->getOption('individual') === true;
            $role = $isIndividual ? null : $this->resolveRole($input, $io, $activity);

            // Assert ActivityInterface is Activity for Track methods
            if (!($activity instanceof \Tcrawf\Zebra\Activity\Activity)) {
                throw new \RuntimeException('Activity must be an instance of Activity');
            }

            $frame = $this->track->start($activity, $description, $startAt, $gap, $isIndividual, $role);

            $io->writeln('<info>Frame started successfully</info>');
            $io->writeln("<info>UUID: {$frame->uuid}</info>");
            $io->writeln("<info>Activity: {$frame->activity->name}</info>");
            if ($frame->isIndividual) {
                $io->writeln("<info>Type: Individual</info>");
            } else {
                $io->writeln("<info>Role: {$frame->role->name}</info>");
            }
            $io->writeln("<info>Description: {$frame->description}</info>");

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
     * Get activity repository instance.
     *
     * @return ActivityRepositoryInterface
     */
    protected function getActivityRepository(): ActivityRepositoryInterface
    {
        return $this->activityRepository;
    }

    /**
     * Get project repository instance.
     *
     * @return ProjectRepositoryInterface
     */
    protected function getProjectRepository(): ProjectRepositoryInterface
    {
        return $this->projectRepository;
    }

    /**
     * Get user repository instance.
     *
     * @return UserRepositoryInterface
     */
    protected function getUserRepository(): UserRepositoryInterface
    {
        return $this->userRepository;
    }

    /**
     * Get frame repository instance.
     *
     * @return FrameRepositoryInterface
     */
    protected function getFrameRepository(): FrameRepositoryInterface
    {
        return $this->frameRepository;
    }

    /**
     * Get config storage instance.
     *
     * @return ConfigFileStorageInterface
     */
    protected function getConfigStorage(): ConfigFileStorageInterface
    {
        return $this->configStorage;
    }

    /**
     * Extract issue keys from description.
     * Issue keys have the format: 2-6 uppercase letters, hyphen, 1-5 digits (e.g., AA-1234, ABC-12345).
     * Uses the same pattern as Frame::extractIssues().
     *
     * @param string $description The description to extract issue keys from
     * @return array<string> Array of issue keys found in the description
     */
    private function extractIssueKeys(string $description): array
    {
        if (empty($description)) {
            return [];
        }

        // Pattern: 2-6 uppercase letters, hyphen, 1-5 digits
        $pattern = '/[A-Z]{2,6}-\d{1,5}/';
        preg_match_all($pattern, $description, $matches);

        // Return unique issue keys
        // preg_match_all always populates $matches[0], even if empty
        return array_values(array_unique($matches[0]));
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('activity')) {
            $this->autocompletion->suggest($input, $suggestions);
        }
    }
}

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
use Tcrawf\Zebra\Exception\InvalidTimeException;
use Tcrawf\Zebra\Frame\FrameRepositoryInterface;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;
use Tcrawf\Zebra\Track\TrackInterface;
use Tcrawf\Zebra\User\UserRepositoryInterface;

class AddCommand extends Command
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
            ->setName('add')
            ->setDescription('Add time to a project that was not tracked live')
            ->addArgument(
                'activity',
                InputArgument::REQUIRED | InputArgument::IS_ARRAY,
                'Activity alias or ID (use +description or +"description text" for frame description)'
            )
            ->addOption('from', 'f', InputOption::VALUE_REQUIRED, 'Start datetime (ISO 8601 format)')
            ->addOption('to', 't', InputOption::VALUE_REQUIRED, 'End datetime (ISO 8601 format)')
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
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Parse arguments to handle +description syntax
        $parsed = $this->parseActivityArguments($input);
        $activityIdentifier = $parsed['activity'];
        $descriptionFromPlus = $parsed['description'];

        // If no activity found, show error
        if ($activityIdentifier === null) {
            $io->writeln('<fg=red>Activity is required</fg=red>');
            return Command::FAILURE;
        }

        $activity = $this->resolveActivity($activityIdentifier, $io);

        if ($activity === null) {
            $io->writeln("<fg=red>Activity '{$activityIdentifier}' not found</fg=red>");
            return Command::FAILURE;
        }

        $fromStr = $input->getOption('from');
        $toStr = $input->getOption('to');

        if ($fromStr === null || $toStr === null) {
            $io->writeln('<fg=red>Both --from and --to options are required</fg=red>');
            return Command::FAILURE;
        }

        try {
            $from = Carbon::parse($fromStr);
            $to = Carbon::parse($toStr);
            // Use description from + syntax if provided, otherwise use option
            $description = $descriptionFromPlus ?? $input->getOption('description');
            $isIndividual = $input->getOption('individual') === true;

            $role = $isIndividual ? null : $this->resolveRole($input, $io, $activity);

            // Assert ActivityInterface is Activity for Track methods
            if (!($activity instanceof \Tcrawf\Zebra\Activity\Activity)) {
                throw new \RuntimeException('Activity must be an instance of Activity');
            }

            $frame = $this->track->add($activity, $from, $to, $description, $isIndividual, $role);

            $duration = $frame->getDuration();
            $durationFormatted = $duration !== null
                ? sprintf('%02d:%02d:%02d', floor($duration / 3600), floor(($duration % 3600) / 60), $duration % 60)
                : 'N/A';

            $io->writeln('<info>Frame added successfully</info>');
            $io->writeln("<info>UUID: {$frame->uuid}</info>");
            $io->writeln("<info>Activity: {$frame->activity->name}</info>");
            if ($frame->isIndividual) {
                $io->writeln("<info>Type: Individual</info>");
            } else {
                $io->writeln("<info>Role: {$frame->role->name}</info>");
            }
            $io->writeln("<info>Duration: {$durationFormatted}</info>");

            return Command::SUCCESS;
        } catch (InvalidTimeException $e) {
            $io->writeln("<fg=red>{$e->getMessage()}</fg=red>");
            return Command::FAILURE;
        } catch (\Exception $e) {
            $io->writeln('<fg=red>An error occurred: ' . $e->getMessage() . '</fg=red>');
            return Command::FAILURE;
        }
    }

    /**
     * Include inactive projects in activity search (AddCommand allows adding time to inactive projects).
     *
     * @return bool
     */
    protected function shouldIncludeInactiveProjects(): bool
    {
        return true;
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

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('activity')) {
            $this->autocompletion->suggest($input, $suggestions);
        }
    }
}

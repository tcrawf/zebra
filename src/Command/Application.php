<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command;

use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tcrawf\Zebra\Activity\ActivityRepository;
use Tcrawf\Zebra\Activity\LocalActivityRepository;
use Tcrawf\Zebra\Activity\ZebraActivityRepository;
use Tcrawf\Zebra\Cache\CacheFileStorageFactory;
use Tcrawf\Zebra\Client\HttpClientFactory;
use Tcrawf\Zebra\Command\Autocompletion\ActivityAutocompletion;
use Tcrawf\Zebra\Command\Autocompletion\ActivityOrProjectAutocompletion;
use Tcrawf\Zebra\Command\Autocompletion\FrameAutocompletion;
use Tcrawf\Zebra\Command\Autocompletion\LocalActivityAutocompletion;
use Tcrawf\Zebra\Command\Autocompletion\LocalProjectAutocompletion;
use Tcrawf\Zebra\Command\Autocompletion\ProjectAutocompletion;
use Tcrawf\Zebra\Command\Autocompletion\TimesheetAutocompletion;
use Tcrawf\Zebra\Config\ConfigFileStorage;
use Tcrawf\Zebra\Config\ConfigFileStorageInterface;
use Tcrawf\Zebra\Frame\FrameFileStorageFactory;
use Tcrawf\Zebra\Frame\FrameRepository;
use Tcrawf\Zebra\Project\LocalProjectRepository;
use Tcrawf\Zebra\Project\ProjectApiService;
use Tcrawf\Zebra\Project\ProjectRepository;
use Tcrawf\Zebra\Project\ProjectApiServiceInterface;
use Tcrawf\Zebra\Project\ZebraProjectRepository;
use Tcrawf\Zebra\Project\ZebraProjectRepositoryInterface;
use Tcrawf\Zebra\Report\ReportService;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;
use Tcrawf\Zebra\Timesheet\LocalTimesheetRepository;
use Tcrawf\Zebra\Timesheet\LocalTimesheetRepositoryInterface;
use Tcrawf\Zebra\Timesheet\TimesheetApiService;
use Tcrawf\Zebra\Timesheet\TimesheetFileStorageFactory;
use Tcrawf\Zebra\Timesheet\TimesheetSyncService;
use Tcrawf\Zebra\Timesheet\TimesheetSyncServiceInterface;
use Tcrawf\Zebra\Timesheet\ZebraTimesheetRepository;
use Tcrawf\Zebra\Timesheet\ZebraTimesheetRepositoryInterface;
use Tcrawf\Zebra\Track\Track;
use Tcrawf\Zebra\User\UserApiService;
use Tcrawf\Zebra\User\UserApiServiceInterface;
use Tcrawf\Zebra\User\UserRepository;

class Application extends SymfonyApplication
{
    private readonly ConfigFileStorageInterface $configStorage;
    private readonly Track $track;
    private readonly ProjectRepository $projectRepository;
    private readonly UserRepository $userRepository;
    private readonly FrameRepository $frameRepository;
    private readonly TimezoneFormatter $timezoneFormatter;
    private readonly ActivityRepository $activityRepository;
    private readonly ReportService $reportService;
    private readonly ActivityOrProjectAutocompletion $activityOrProjectAutocompletion;
    private readonly FrameAutocompletion $frameAutocompletion;
    private readonly ZebraProjectRepositoryInterface $zebraProjectRepository;
    private readonly UserApiServiceInterface $userApiService;
    private readonly ProjectApiServiceInterface $projectApiService;
    private readonly BackupCommand $backupCommand;
    private readonly LocalTimesheetRepositoryInterface $timesheetRepository;
    private readonly ZebraTimesheetRepositoryInterface $zebraTimesheetRepository;
    private readonly TimesheetSyncServiceInterface $timesheetSyncService;
    private readonly TimesheetAutocompletion $timesheetAutocompletion;
    private readonly LocalProjectAutocompletion $localProjectAutocompletion;
    private readonly LocalActivityAutocompletion $localActivityAutocompletion;
    private readonly ProjectAutocompletion $projectAutocompletion;

    public function __construct()
    {
        parent::__construct('zebra', '1.0.5-alpha');

        // Initialize services directly in constructor for readonly properties
        $this->configStorage = new ConfigFileStorage();
        $cacheFactory = new CacheFileStorageFactory();
        $client = HttpClientFactory::create();

        $this->userApiService = new UserApiService($client);
        $this->userRepository = new UserRepository($this->userApiService, $cacheFactory, $this->configStorage);

        $frameStorageFactory = new FrameFileStorageFactory();
        $this->frameRepository = new FrameRepository($frameStorageFactory);

        // Initialize project repositories
        $this->projectApiService = new ProjectApiService($client);
        $this->zebraProjectRepository = new ZebraProjectRepository($this->projectApiService, $cacheFactory);
        $localProjectRepository = new LocalProjectRepository();
        $this->projectRepository = new ProjectRepository($localProjectRepository, $this->zebraProjectRepository);

        // Initialize activity repositories
        $zebraActivityRepository = new ZebraActivityRepository($this->zebraProjectRepository);
        $localActivityRepository = new LocalActivityRepository($localProjectRepository, $this->frameRepository);
        $this->activityRepository = new ActivityRepository($localActivityRepository, $zebraActivityRepository);

        $this->timezoneFormatter = new TimezoneFormatter();
        $this->reportService = new ReportService($this->projectRepository, $this->timezoneFormatter);

        // Initialize autocompletion
        $activityAutocompletion = new ActivityAutocompletion($this->projectRepository);
        $this->projectAutocompletion = new ProjectAutocompletion($this->projectRepository);
        $this->localProjectAutocompletion = new LocalProjectAutocompletion($this->projectRepository);
        $this->localActivityAutocompletion = new LocalActivityAutocompletion($this->projectRepository);
        $this->activityOrProjectAutocompletion = new ActivityOrProjectAutocompletion(
            $activityAutocompletion,
            $this->projectAutocompletion
        );
        $this->frameAutocompletion = new FrameAutocompletion($this->frameRepository, $this->timezoneFormatter);

        $this->track = new Track(
            $this->frameRepository,
            $this->configStorage,
            $this->projectRepository,
            $this->userRepository,
            $this->timezoneFormatter
        );

        // Initialize timesheet repositories
        $timesheetStorageFactory = new TimesheetFileStorageFactory();
        $this->timesheetRepository = new LocalTimesheetRepository($timesheetStorageFactory);
        $timesheetApiService = new TimesheetApiService($client);
        $this->zebraTimesheetRepository = new ZebraTimesheetRepository(
            $timesheetApiService,
            $this->activityRepository,
            $this->userRepository
        );
        $this->timesheetSyncService = new TimesheetSyncService(
            $this->timesheetRepository,
            $this->zebraTimesheetRepository
        );
        $this->timesheetAutocompletion = new TimesheetAutocompletion($this->timesheetRepository);

        // Initialize backup command for automatic daily backups
        $this->backupCommand = new BackupCommand();

        $this->registerCommands();
    }

    private function registerCommands(): void
    {
        $this->add(
            new StartCommand(
                $this->track,
                $this->activityRepository,
                $this->userRepository,
                $this->frameRepository,
                $this->configStorage,
                $this->projectRepository,
                $this->activityOrProjectAutocompletion
            )
        );
        $this->add(new StopCommand($this->track));
        $this->add(new StatusCommand($this->track, $this->timezoneFormatter, $this->projectRepository));
        $this->add(new CancelCommand($this->track));
        $this->add(
            new RestartCommand(
                $this->track,
                $this->frameRepository,
                $this->frameAutocompletion,
                $this->timezoneFormatter
            )
        );
        $this->add(
            new AddCommand(
                $this->track,
                $this->activityRepository,
                $this->userRepository,
                $this->frameRepository,
                $this->configStorage,
                $this->projectRepository,
                $this->activityOrProjectAutocompletion
            )
        );
        $this->add(
            new ProjectsCommand($this->projectRepository, $this->localProjectAutocompletion)
        );
        $this->add(
            new ActivitiesCommand(
                $this->projectRepository,
                $this->activityRepository,
                $this->localActivityAutocompletion
            )
        );
        $this->add(
            new DeleteProjectCommand(
                $this->projectRepository,
                $this->activityRepository,
                $this->frameRepository,
                $this->localProjectAutocompletion
            )
        );
        $this->add(
            new DeleteActivityCommand(
                $this->activityRepository,
                $this->projectRepository,
                $this->frameRepository,
                $this->localActivityAutocompletion
            )
        );
        $this->add(new FramesCommand($this->frameRepository));
        $this->add(
            new EditCommand(
                $this->frameRepository,
                $this->timezoneFormatter,
                $this->activityRepository,
                $this->userRepository,
                $this->frameAutocompletion
            )
        );
        $this->add(new RemoveCommand($this->frameRepository, $this->frameAutocompletion));
        $this->add(new ConfigCommand($this->configStorage));
        $this->add(new ReportCommand($this->frameRepository, $this->reportService));
        $this->add(
            new AggregateCommand(
                $this->frameRepository,
                $this->reportService,
                $this->timezoneFormatter,
                $this->projectRepository
            )
        );
        $this->add(
            new LogCommand(
                $this->frameRepository,
                $this->timezoneFormatter,
                $this->projectRepository,
                $this->projectAutocompletion
            )
        );
        $this->add(new InstallCommand());
        $this->add(new UserCommand($this->userRepository, $this->configStorage));
        $this->add(new RolesCommand($this->userRepository));
        $this->add($this->backupCommand);
        $this->add(new RestoreCommand());
        $this->add(new DeleteBackupCommand());
        $this->add(
            new RefreshCommand(
                $this->userRepository,
                $this->zebraProjectRepository,
                $this->userApiService,
                $this->projectApiService,
                $this->configStorage
            )
        );
        $this->add(
            new TimesheetCreateCommand(
                $this->timesheetRepository,
                $this->activityRepository,
                $this->userRepository,
                $this->activityOrProjectAutocompletion
            )
        );
        $this->add(
            new TimesheetFromFramesCommand(
                $this->frameRepository,
                $this->reportService,
                $this->timesheetRepository
            )
        );
        $this->add(
            new TimesheetEditCommand(
                $this->timesheetRepository,
                $this->zebraTimesheetRepository,
                $this->activityRepository,
                $this->userRepository,
                $this->timesheetAutocompletion
            )
        );
        $this->add(new TimesheetListCommand($this->timesheetRepository, $this->frameRepository));
        $this->add(
            new TimesheetPushCommand(
                $this->timesheetRepository,
                $this->zebraTimesheetRepository,
                $this->timesheetSyncService,
                $this->timesheetAutocompletion
            )
        );
        $this->add(
            new TimesheetPullCommand(
                $this->timesheetRepository,
                $this->zebraTimesheetRepository,
                $this->timesheetSyncService,
                $this->timesheetAutocompletion
            )
        );
        $this->add(
            new TimesheetDeleteCommand(
                $this->timesheetRepository,
                $this->zebraTimesheetRepository,
                $this->timesheetAutocompletion
            )
        );
        $this->add(
            new TimesheetMergeCommand(
                $this->timesheetRepository,
                $this->timesheetAutocompletion
            )
        );
    }

    /**
     * Override doRun to check for daily backup before executing commands.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        // Get the command name being executed
        $commandName = $input->getFirstArgument();

        // Skip auto-backup if:
        // 1. No command specified (showing help/list)
        // 2. The backup command itself is being executed
        // 3. The command is 'list' or 'help' (to avoid backup on every help request)
        if ($commandName !== null && $commandName !== 'backup' && $commandName !== 'list' && $commandName !== 'help') {
            // Check if backup exists for today
            if (!$this->backupCommand->hasBackupForToday()) {
                // Silently create backup for today
                $this->backupCommand->executeSilently();
            }
        }

        // Continue with normal command execution
        return parent::doRun($input, $output);
    }
}

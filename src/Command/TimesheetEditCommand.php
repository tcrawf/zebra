<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command;

use Carbon\Carbon;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Activity\ActivityRepositoryInterface;
use Tcrawf\Zebra\Command\Autocompletion\TimesheetAutocompletion;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Role\RoleInterface;
use Tcrawf\Zebra\Timesheet\LocalTimesheetRepositoryInterface;
use Tcrawf\Zebra\Timesheet\TimesheetFactory;
use Tcrawf\Zebra\Timesheet\TimesheetInterface;
use Tcrawf\Zebra\Timesheet\ZebraTimesheetRepositoryInterface;
use Tcrawf\Zebra\User\UserRepositoryInterface;
use Tcrawf\Zebra\Uuid\Uuid;

class TimesheetEditCommand extends Command
{
    use PhpUnitDetectionTrait;

    public function __construct(
        private readonly LocalTimesheetRepositoryInterface $timesheetRepository,
        private readonly ZebraTimesheetRepositoryInterface $zebraTimesheetRepository,
        private readonly ActivityRepositoryInterface $activityRepository,
        private readonly UserRepositoryInterface $userRepository,
        private readonly TimesheetAutocompletion $timesheetAutocompletion
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('timesheet:edit')
            ->setDescription('Edit a timesheet entry')
            ->addArgument('timesheet', InputArgument::OPTIONAL, 'Timesheet UUID')
            ->addOption('editor', null, InputOption::VALUE_OPTIONAL, 'Editor command (default: $EDITOR or $VISUAL)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $timesheetIdentifier = $input->getArgument('timesheet');
        $timesheet = $this->resolveTimesheet($timesheetIdentifier, $io);

        if ($timesheet === null) {
            if ($timesheetIdentifier === null) {
                $io->writeln('<fg=red>No timesheet specified. Please provide a timesheet UUID.</fg=red>');
            } else {
                $io->writeln("<fg=red>Timesheet '{$timesheetIdentifier}' not found</fg=red>");
            }
            return Command::FAILURE;
        }

        // Check sync status before editing
        if ($timesheet->zebraId !== null) {
            $shouldSync = $this->checkSyncStatus($timesheet, $io, $input);
            if ($shouldSync) {
                // User chose to sync, fetch from Zebra and update local
                try {
                    $remoteTimesheet = $this->zebraTimesheetRepository->getByZebraId($timesheet->zebraId);
                    if ($remoteTimesheet !== null) {
                        // Update local timesheet with remote data, preserving UUID, frameUuids, and doNotSync
                        $existingUuid = Uuid::fromHex($timesheet->uuid);
                        $updatedTimesheet = TimesheetFactory::create(
                            $remoteTimesheet->activity,
                            $remoteTimesheet->description,
                            $remoteTimesheet->clientDescription,
                            $remoteTimesheet->time,
                            $remoteTimesheet->date,
                            $remoteTimesheet->role,
                            $remoteTimesheet->individualAction,
                            $timesheet->frameUuids, // Preserve original frame UUIDs
                            $remoteTimesheet->zebraId,
                            $remoteTimesheet->updatedAt,
                            $existingUuid, // Preserve existing UUID to update, not create new
                            $timesheet->doNotSync // Preserve doNotSync flag
                        );

                        // Verify UUID was preserved
                        if ($updatedTimesheet->uuid !== $timesheet->uuid) {
                            throw new \RuntimeException(
                                sprintf(
                                    'UUID mismatch: expected %s, got %s. Cannot sync.',
                                    $timesheet->uuid,
                                    $updatedTimesheet->uuid
                                )
                            );
                        }

                        // Use update() to ensure we're updating the existing timesheet, not creating a new one
                        $this->timesheetRepository->update($updatedTimesheet);
                        $timesheet = $updatedTimesheet;
                        $io->success('Timesheet synced from Zebra. Proceeding with edit...');
                    }
                } catch (\Exception $e) {
                    $io->warning('Failed to sync from Zebra: ' . $e->getMessage());
                    $io->writeln('Proceeding with local version...');
                }
            }
        }

        // Create JSON template
        $data = [
            'activity' => [
                'key' => [
                    'source' => $timesheet->activity->entityKey->source->value,
                    'id' => $timesheet->activity->alias ?? $timesheet->activity->entityKey->toString(),
                ],
            ],
            'description' => $timesheet->description,
            'clientDescription' => $timesheet->clientDescription,
            'time' => $timesheet->time,
            'date' => $timesheet->date->format('Y-m-d'),
            'isIndividual' => $timesheet->individualAction,
            'role_id' => $timesheet->role !== null ? $timesheet->role->id : null,
            'doNotSync' => $timesheet->doNotSync,
        ];

        $jsonTemplate = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Open in editor with retry loop
        $editor = $this->getEditor($input);
        $text = $jsonTemplate;

        while (true) {
            $editedJson = $this->openInEditor($text, $editor);

            // User cancelled or made no changes
            if ($editedJson === null || $editedJson === $text) {
                $io->info('No changes made.');
                return Command::SUCCESS;
            }

            try {
                $editedData = json_decode($editedJson, true, 512, JSON_THROW_ON_ERROR);

                // Get activity (use edited activity if provided, otherwise keep existing)
                $activity = $timesheet->activity;
                if (isset($editedData['activity']) && isset($editedData['activity']['key'])) {
                    $identifier = $editedData['activity']['key']['id'];

                    // Try to resolve by alias first
                    $newActivity = $this->activityRepository->getByAlias($identifier);

                    if ($newActivity === null) {
                        // Not found by alias, try as ID
                        $source = \Tcrawf\Zebra\EntityKey\EntitySource::from($editedData['activity']['key']['source']);

                        if (ctype_digit($identifier)) {
                            // Numeric - try as Zebra ID
                            $entityKey = new EntityKey($source, (int) $identifier);
                            $newActivity = $this->activityRepository->get($entityKey);
                        } else {
                            // Try as UUID string for local activities
                            try {
                                $uuid = Uuid::fromHex($identifier);
                                $entityKey = new EntityKey($source, $uuid);
                                $newActivity = $this->activityRepository->get($entityKey);
                            } catch (\Exception $e) {
                                // Not a valid UUID, continue to error
                            }
                        }
                    }

                    if ($newActivity === null) {
                        throw new \RuntimeException(
                            "Activity with alias or ID '{$identifier}' not found."
                        );
                    }
                    $activity = $newActivity;
                }

                // Get isIndividual flag
                $isIndividual = $timesheet->individualAction;
                if (isset($editedData['isIndividual'])) {
                    $isIndividual = (bool) $editedData['isIndividual'];
                }

                // Get role
                $role = $timesheet->role;
                if (isset($editedData['role_id'])) {
                    if ($isIndividual) {
                        throw new \RuntimeException(
                            'Cannot set role_id for individual timesheets. Set isIndividual to false first.'
                        );
                    }
                    $newRole = $this->validateAndGetRole($editedData['role_id'], $io);
                    if ($newRole === null) {
                        throw new \RuntimeException('Invalid role ID.');
                    }
                    $role = $newRole;
                } elseif (array_key_exists('role_id', $editedData) && $editedData['role_id'] === null) {
                    $role = null;
                }

                // Validate: if switching from individual to non-individual, require role
                if (!$isIndividual && $role === null) {
                    throw new \RuntimeException(
                        'Non-individual timesheets must have a role. Set role_id or set isIndividual to true.'
                    );
                }

                // Validate: if switching to individual, clear role
                if ($isIndividual && $role !== null) {
                    $role = null;
                }

                // Parse date
                if (!isset($editedData['date'])) {
                    throw new \RuntimeException('Date is required.');
                }
                try {
                    // Parse date-only strings (Y-m-d format) as Europe/Zurich to match API timezone
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $editedData['date'])) {
                        $date = Carbon::parse($editedData['date'], 'Europe/Zurich')->startOfDay();
                    } else {
                        // For other formats, parse in local timezone then convert to Europe/Zurich
                        $localTimezone = date_default_timezone_get();
                        $localParsed = Carbon::parse($editedData['date'], $localTimezone);
                        $date = $localParsed->setTimezone('Europe/Zurich')->startOfDay();
                    }
                } catch (\Exception $e) {
                    throw new \RuntimeException("Invalid date format: {$editedData['date']}. Use YYYY-MM-DD format.");
                }

                // Validate time
                if (!isset($editedData['time']) || !is_numeric($editedData['time'])) {
                    throw new \RuntimeException('Time is required and must be numeric.');
                }
                $time = (float) $editedData['time'];

                // Validate time is a multiple of 0.25
                $remainder = fmod($time * 100, 25);
                if (abs($remainder) > 0.0001) {
                    throw new \RuntimeException("Time must be a multiple of 0.25, got: {$time}");
                }

                if ($time <= 0) {
                    throw new \RuntimeException("Time must be positive, got: {$time}");
                }

                // Get description
                $description = $editedData['description'] ?? $timesheet->description;
                if (empty(trim($description))) {
                    throw new \RuntimeException('Description cannot be empty.');
                }

                // Get client description
                $clientDescription = $editedData['clientDescription'] ?? $timesheet->clientDescription;
                if ($clientDescription !== null && empty(trim($clientDescription))) {
                    $clientDescription = null;
                }

                // Preserve UUID, frameUuids, zebraId, and updatedAt
                $uuid = Uuid::fromHex($timesheet->uuid);
                $frameUuids = $timesheet->frameUuids;
                $zebraId = $timesheet->zebraId;
                $updatedAt = $timesheet->updatedAt;

                // Get doNotSync flag (use edited value if provided, otherwise keep existing)
                $doNotSync = $timesheet->doNotSync;
                if (isset($editedData['doNotSync'])) {
                    $doNotSync = (bool) $editedData['doNotSync'];
                }

                // Recreate timesheet with edited data
                $updatedTimesheet = TimesheetFactory::create(
                    $activity,
                    $description,
                    $clientDescription,
                    $time,
                    $date,
                    $role,
                    $isIndividual,
                    $frameUuids,
                    $zebraId,
                    $updatedAt,
                    $uuid,
                    $doNotSync
                );

                // Save the updated timesheet
                $this->timesheetRepository->save($updatedTimesheet);

                $io->writeln('<info>Timesheet updated successfully.</info>');

                return Command::SUCCESS;
            } catch (\JsonException $e) {
                $io->writeln(
                    '<error>Error while parsing inputted values: Invalid JSON: ' . $e->getMessage() . '</error>'
                );
                $this->pauseForUser($input, $io);
                $text = $editedJson;
            } catch (\RuntimeException $e) {
                $io->writeln('<error>Error while parsing inputted values: ' . $e->getMessage() . '</error>');
                $this->pauseForUser($input, $io);
                $text = $editedJson;
            } catch (\Exception $e) {
                $io->writeln('<error>Error while parsing inputted values: ' . $e->getMessage() . '</error>');
                $this->pauseForUser($input, $io);
                $text = $editedJson;
            }
        }
    }

    /**
     * Resolve a timesheet by UUID.
     *
     * @param string|null $identifier Timesheet UUID
     * @return TimesheetInterface|null
     */
    private function resolveTimesheet(?string $identifier, SymfonyStyle $io): ?TimesheetInterface
    {
        if ($identifier === null) {
            return null;
        }

        return $this->timesheetRepository->get($identifier);
    }

    /**
     * Check sync status and prompt user if remote is newer.
     *
     * @param TimesheetInterface $timesheet
     * @param SymfonyStyle $io
     * @param InputInterface $input
     * @return bool True if user wants to sync from Zebra
     */
    private function checkSyncStatus(TimesheetInterface $timesheet, SymfonyStyle $io, InputInterface $input): bool
    {
        $io->writeln('<comment>This timesheet has been synced to Zebra (ID: ' . $timesheet->zebraId . ').</comment>');
        $io->writeln('<comment>The remote record may have been updated. Checking for updates...</comment>');

        try {
            // Fetch remote timesheet
            $remoteTimesheet = $this->zebraTimesheetRepository->getByZebraId($timesheet->zebraId);
            if ($remoteTimesheet === null) {
                $io->warning('Could not fetch timesheet from Zebra. Proceeding with local version.');
                return false;
            }

            // Get remote modified date (modified or lu_date) - both are in Europe/Zurich timezone
            // Parse from API response directly to ensure we get the raw date string
            // Note: fetchRawApiData returns the 'data' part of the API response, not the full response
            $apiData = $this->zebraTimesheetRepository->fetchRawApiData($timesheet->zebraId);

            // Check if we have valid data
            if (empty($apiData)) {
                $io->error('Invalid API response structure. Cannot determine remote modification date.');
                if (!$input->isInteractive()) {
                    return false;
                }
                $question = new ConfirmationQuestion(
                    'Proceed with local version anyway? [y/N]: ',
                    false
                );
                return !$io->askQuestion($question);
            }

            // fetchRawApiData returns the data part directly, so access fields at top level
            $remoteModifiedStr = $apiData['modified'] ?? $apiData['lu_date'] ?? null;

            if ($remoteModifiedStr === null || empty($remoteModifiedStr)) {
                $io->error('Could not determine remote modification date from API response.');
                $io->writeln('API response keys: ' . implode(', ', array_keys($apiData)));
                if (!$input->isInteractive()) {
                    return false;
                }
                $question = new ConfirmationQuestion(
                    'Proceed with local version anyway? [y/N]: ',
                    false
                );
                return !$io->askQuestion($question);
            }

            // Parse remote date from Europe/Zurich timezone and convert to UTC
            try {
                $remoteModified = Carbon::parse($remoteModifiedStr, 'Europe/Zurich')->utc();
            } catch (\Exception $e) {
                $io->error(sprintf(
                    'Failed to parse remote modification date "%s": %s',
                    $remoteModifiedStr,
                    $e->getMessage()
                ));
                if (!$input->isInteractive()) {
                    return false;
                }
                $question = new ConfirmationQuestion(
                    'Proceed with local version anyway? [y/N]: ',
                    false
                );
                return !$io->askQuestion($question);
            }

            // Compare with local updatedAt (already in UTC)
            $localUpdatedAt = $timesheet->updatedAt;

            if ($remoteModified->gt($localUpdatedAt)) {
                $io->writeln('');
                $io->writeln('<warning>The remote timesheet is newer than your local version.</warning>');
                $io->writeln(sprintf(
                    '  Local:  %s',
                    $localUpdatedAt->format('Y-m-d H:i:s T')
                ));
                $io->writeln(sprintf(
                    '  Remote: %s (converted from Europe/Zurich)',
                    $remoteModified->format('Y-m-d H:i:s T')
                ));
                $io->writeln('');

                $question = new ConfirmationQuestion(
                    'Would you like to sync the data from Zebra before editing? [y/N]: ',
                    false
                );

                return $io->askQuestion($question);
            }

            // Remote is not newer, proceed with local version
            return false;
        } catch (\Exception $e) {
            $io->warning('Error checking sync status: ' . $e->getMessage());
            $io->writeln('Proceeding with local version...');
            return false;
        }
    }

    /**
     * Get the editor command to use.
     *
     * @param InputInterface $input
     * @return string
     */
    private function getEditor(InputInterface $input): string
    {
        $editor = $input->getOption('editor');
        if ($editor !== null) {
            return $editor;
        }

        // Try environment variables
        $editor = getenv('VISUAL');
        if ($editor !== false) {
            return $editor;
        }

        $editor = getenv('EDITOR');
        if ($editor !== false) {
            return $editor;
        }

        // Default editors
        if (PHP_OS_FAMILY === 'Windows') {
            return 'notepad';
        }

        // Try common Unix editors
        $editors = ['vim', 'nano', 'vi'];
        foreach ($editors as $ed) {
            $whichCommand = 'which ' . escapeshellarg($ed) . ' 2>/dev/null';
            if (shell_exec($whichCommand) !== null) {
                return $ed;
            }
        }

        return 'vi'; // Fallback
    }

    /**
     * Validate editor command to prevent command injection.
     *
     * @param string $editor
     * @return string|null Returns validated editor command or null if invalid
     */
    private function validateEditorCommand(string $editor): ?string
    {
        // Remove any whitespace
        $editor = trim($editor);

        // Empty string is invalid
        if ($editor === '') {
            return null;
        }

        // Prevent null bytes and invalid path characters
        // Since we use array syntax with proc_open(), shell metacharacters won't execute,
        // but we still reject them as invalid path characters
        if (str_contains($editor, "\0") || str_contains($editor, ';') || str_contains($editor, ' ')) {
            return null;
        }

        // Check if it's a simple executable name (no path separators)
        $isSimpleName = !str_contains($editor, '/') && !str_contains($editor, '\\');

        if ($isSimpleName) {
            // Simple executable name - use basename() to ensure no path components
            // and validate it contains only safe characters using ctype functions
            $commandName = basename($editor);
            if ($commandName === $editor && ctype_alnum(str_replace(['-', '_', '.'], '', $commandName))) {
                return $editor;
            }
            return null;
        }

        // It's a path - must be absolute (start with / on Unix or drive letter on Windows)
        // Relative paths are not allowed for security (prevents directory traversal)
        $isAbsolute = str_starts_with($editor, '/')
            || (PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Z]:/i', $editor));

        if (!$isAbsolute) {
            // Relative paths are not allowed - they could be used for directory traversal
            return null;
        }

        // Resolve path to prevent directory traversal
        // Use realpath() to resolve any .. components and normalize the path
        $resolvedPath = realpath($editor);

        if ($resolvedPath !== false) {
            // Path exists and was resolved - use the resolved path
            // This ensures any .. components are resolved
            $editor = $resolvedPath;
        } else {
            // Path doesn't exist yet (e.g., test editor scripts)
            // Still validate it's an absolute path and doesn't contain dangerous patterns
            if (str_contains($editor, '..')) {
                return null;
            }
        }

        return $editor;
    }

    /**
     * Open content in editor and return edited content.
     *
     * @param string $content
     * @param string $editor
     * @return string|null
     */
    private function openInEditor(string $content, string $editor): ?string
    {
        // Validate editor command before use
        $validatedEditor = $this->validateEditorCommand($editor);
        if ($validatedEditor === null) {
            return null;
        }

        // Check if we're running in a test environment
        $isTestEnvironment = $this->isPhpUnitRunning();

        // Check if we're in a non-interactive environment
        $isStdinTty = function_exists('stream_isatty')
            && defined('STDIN')
            && is_resource(STDIN)
            && stream_isatty(STDIN);

        // Check if editor is likely a test script (non-interactive)
        // Test scripts should NEVER be connected to real STDIN, even if STDIN is a TTY
        $isTestScript = str_contains($validatedEditor, 'test')
            || str_starts_with($validatedEditor, sys_get_temp_dir())
            || str_contains($validatedEditor, 'zebra_test_editor');

        // In test environments, only allow test scripts
        if ($isTestEnvironment && !$isTestScript) {
            return null;
        }

        // If STDIN is not a TTY and it's not a test script, don't open interactive editors
        if (!$isStdinTty && !$isTestScript) {
            return null;
        }

        // Create temp file with .json extension so nano can detect syntax highlighting
        $tmpFile = sys_get_temp_dir() . '/zebra_timesheet_edit_' . uniqid('', true) . '.json';
        file_put_contents($tmpFile, $content);

        // Open editor using proc_open for better control over process handling
        // Use array syntax to prevent command injection
        // ALWAYS redirect STDIN for test scripts to prevent hanging, even if STDIN is a TTY
        // After the early return check above, if we're not a test script, STDIN must be a TTY (interactive)
        if ($isTestScript) {
            // Non-interactive: use pipes and redirect STDIN to null device
            $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
            $descriptorspec = [
                0 => ['file', $nullDevice, 'r'], // Redirect STDIN to null device
                1 => ['pipe', 'w'],              // Capture stdout
                2 => ['pipe', 'w'],              // Capture stderr
            ];
        } else {
            // Interactive: connect directly to terminal
            $descriptorspec = [
                0 => STDIN,   // stdin
                1 => STDOUT,  // stdout
                2 => STDERR,  // stderr
            ];
        }

        // Use array syntax for proc_open - safer than string command
        // Environment variables are inherited automatically, so TERM will be available if set
        $process = @proc_open([$validatedEditor, $tmpFile], $descriptorspec, $pipes);

        if (!is_resource($process)) {
            unlink($tmpFile);
            return null;
        }

        // Close any pipes we're not using (proc_open might create them)
        // For non-interactive scripts, we need to read and close stdout/stderr pipes
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                // For test scripts, read any output to prevent blocking
                // After the early return check above, if we're not a test script, STDIN must be a TTY (interactive)
                if ($isTestScript) {
                    stream_set_blocking($pipe, false);
                    // Read any available data (non-blocking)
                    while (!feof($pipe)) {
                        $data = fread($pipe, 8192);
                        if ($data === false || $data === '') {
                            break;
                        }
                    }
                }
                fclose($pipe);
            }
        }

        // Close pipes
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        // Wait for process to complete
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            unlink($tmpFile);
            return null;
        }

        $editedContent = file_get_contents($tmpFile);
        unlink($tmpFile);

        return $editedContent;
    }

    /**
     * Pause and wait for user to press Enter.
     *
     * @param InputInterface $input
     * @param SymfonyStyle $io
     * @return void
     */
    private function pauseForUser(InputInterface $input, SymfonyStyle $io): void
    {
        $io->writeln('<comment>Press Enter to continue editing...</comment>');
        // Only pause if input is interactive, STDIN is available, is a TTY, and not in test environment
        // This prevents hanging in non-interactive environments and tests
        // Check multiple conditions to ensure we don't block in test environments
        $phpunitRunning = getenv('PHPUNIT_RUNNING');
        $phpunitEnv = getenv('PHPUNIT');
        $isTestEnvironment = ($phpunitRunning !== false && $phpunitRunning !== '') ||
            ($phpunitEnv !== false && $phpunitEnv !== '') ||
            class_exists(\PHPUnit\Framework\TestCase::class, false);

        $shouldPause = $input->isInteractive() &&
            !$isTestEnvironment &&
            defined('STDIN') &&
            is_resource(STDIN) &&
            stream_isatty(STDIN);

        if ($shouldPause) {
            $line = fgets(STDIN);
            // Consume the input even if empty (user just pressed Enter)
            if ($line === false) {
                // STDIN was closed, continue without blocking
                return;
            }
        }
    }

    /**
     * Validate that the current user has the given role and return it.
     *
     * @param int $roleId
     * @param SymfonyStyle $io
     * @return RoleInterface|null
     */
    private function validateAndGetRole(int $roleId, SymfonyStyle $io): ?RoleInterface
    {
        $user = $this->userRepository->getCurrentUser();
        if ($user === null) {
            $io->writeln(
                '<fg=red>No user ID found in config. Please run "zebra user --init" to set up a user.</fg=red>'
            );
            return null;
        }

        // Find the role in the user's roles
        foreach ($user->roles as $userRole) {
            if ($userRole->id === $roleId) {
                return $userRole;
            }
        }

        $io->writeln("<fg=red>Role with ID {$roleId} not found in current user's roles.</fg=red>");
        return null;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('timesheet')) {
            $this->timesheetAutocompletion->suggest($input, $suggestions);
        }
    }
}

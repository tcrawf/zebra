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
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Activity\ActivityRepositoryInterface;
use Tcrawf\Zebra\Command\Autocompletion\FrameAutocompletion;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Frame\FrameFactory;
use Tcrawf\Zebra\Frame\FrameInterface;
use Tcrawf\Zebra\Frame\FrameRepositoryInterface;
use Tcrawf\Zebra\Role\RoleInterface;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;
use Tcrawf\Zebra\User\UserRepositoryInterface;
use Tcrawf\Zebra\Uuid\Uuid;

class EditCommand extends Command
{
    use PhpUnitDetectionTrait;

    public function __construct(
        private readonly FrameRepositoryInterface $frameRepository,
        private readonly TimezoneFormatter $timezoneFormatter,
        private readonly ActivityRepositoryInterface $activityRepository,
        private readonly UserRepositoryInterface $userRepository,
        private readonly FrameAutocompletion $autocompletion
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('edit')
            ->setDescription('Edit a frame')
            ->addArgument('frame', InputArgument::OPTIONAL, 'Frame UUID or index (-1 for last, -2 for second-to-last)')
            ->addOption('editor', null, InputOption::VALUE_OPTIONAL, 'Editor command (default: $EDITOR or $VISUAL)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $frameIdentifier = $input->getArgument('frame');
        $frameResult = $this->resolveFrame($frameIdentifier);

        if ($frameResult === null) {
            if ($frameIdentifier === null) {
                $io->writeln('<fg=red>No frames found. Please create a frame first.</fg=red>');
            } else {
                $io->writeln("<fg=red>Frame '{$frameIdentifier}' not found</fg=red>");
            }
            return Command::FAILURE;
        }

        // frameResult is an array with 'frame' and 'isCurrent' keys
        $frame = $frameResult['frame'];
        $isCurrentFrame = $frameResult['isCurrent'];

        // Create JSON template in local timezone format (YYYY-MM-DD HH:mm:ss) like watson
        // Note: issue_keys are automatically extracted from description by FrameFactory
        $dateFormat = 'Y-m-d H:i:s'; // Matches watson's 'YYYY-MM-DD HH:mm:ss' format
        $localStart = $this->timezoneFormatter->toLocal($frame->startTime);

        // Use alias if available, otherwise use ID (user can enter either)
        $activityIdentifier = $frame->activity->alias ?? $frame->activity->entityKey->toString();

        $data = [
            'start' => $localStart->format($dateFormat),
        ];

        // Add stop immediately after start (if not an active frame)
        if ($frame->stopTime !== null) {
            $localStop = $this->timezoneFormatter->toLocal($frame->stopTime);
            $data['stop'] = $localStop->format($dateFormat);
        }

        // Add remaining fields after start/stop
        $data['activity'] = [
            'key' => [
                'source' => $frame->activity->entityKey->source->value,
                'id' => $activityIdentifier, // Can be alias or ID
            ],
        ];
        $data['description'] = $frame->description;
        $data['isIndividual'] = $frame->isIndividual;
        $data['role_id'] = $frame->role !== null ? $frame->role->id : null;

        $jsonTemplate = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Open in editor with retry loop (Watson-style)
        $editor = $this->getEditor($input);
        $text = $jsonTemplate; // Start with original template

        while (true) {
            $editedJson = $this->openInEditor($text, $editor);

            // User cancelled or made no changes
            if ($editedJson === null || $editedJson === $text) {
                $io->info('No changes made.');
                return Command::SUCCESS;
            }

            try {
                $editedData = json_decode($editedJson, true, 512, JSON_THROW_ON_ERROR);

                // Validate required fields
                if (!isset($editedData['start'])) {
                    throw new \RuntimeException('Edited frame must contain start key.');
                }

                // Get activity (use edited activity if provided, otherwise keep existing)
                // The 'id' field in activity.key can contain either an alias or an ID
                $activity = $frame->activity;
                if (isset($editedData['activity']) && isset($editedData['activity']['key'])) {
                    $identifier = $editedData['activity']['key']['id'];

                    // Try to resolve by alias first (searches both local and zebra)
                    $newActivity = $this->activityRepository->getByAlias($identifier);

                    if ($newActivity === null) {
                        // Not found by alias, try as ID
                        // Use the source from edited data to determine which repository to check
                        $source = EntitySource::from($editedData['activity']['key']['source']);

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

                // Get isIndividual flag (use edited value if provided, otherwise keep existing)
                $isIndividual = $frame->isIndividual;
                if (isset($editedData['isIndividual'])) {
                    $isIndividual = (bool) $editedData['isIndividual'];
                }

                // Get role (use edited role_id if provided, otherwise keep existing)
                $role = $frame->role;
                if (isset($editedData['role_id'])) {
                    if ($isIndividual) {
                        throw new \RuntimeException(
                            'Cannot set role_id for individual frames. Set isIndividual to false first.'
                        );
                    }
                    $newRole = $this->validateAndGetRole($editedData['role_id'], $io);
                    if ($newRole === null) {
                        throw new \RuntimeException('Invalid role ID.');
                    }
                    $role = $newRole;
                } elseif (array_key_exists('role_id', $editedData) && $editedData['role_id'] === null) {
                    // Explicitly setting role_id to null
                    $role = null;
                }

                // Validate: if switching from individual to non-individual, require role
                if (!$isIndividual && $role === null) {
                    throw new \RuntimeException(
                        'Non-individual frames must have a role. Set role_id or set isIndividual to true.'
                    );
                }

                // Validate: if switching to individual, clear role
                if ($isIndividual && $role !== null) {
                    $role = null;
                }

                // Parse dates from local timezone format and convert to UTC for storage
                // The dates are in local timezone format (YYYY-MM-DD HH:mm:ss)
                $startTime = $this->timezoneFormatter->parseLocalToUtc($editedData['start']);
                $stopTime = isset($editedData['stop'])
                    ? $this->timezoneFormatter->parseLocalToUtc($editedData['stop'])
                    : null;

                // For current (active) frames, stop time is optional
                // If stop time is provided for current frame, it will stop the frame
                // If stop time is not provided, frame remains active
                if ($isCurrentFrame && $stopTime === null) {
                    // Current frame remains active - no stop time
                } elseif ($stopTime !== null) {
                    // Validate stop time is after start time (for completed frames or when stopping current)
                    if ($stopTime->lt($startTime)) {
                        throw new \RuntimeException('Stop time must be after start time.');
                    }
                }

                // Validate times are not in the future
                $now = Carbon::now()->utc();
                if ($startTime->gt($now)) {
                    throw new \RuntimeException('Start time cannot be in the future.');
                }
                if ($stopTime !== null && $stopTime->gt($now)) {
                    throw new \RuntimeException('Stop time cannot be in the future.');
                }

                // Preserve UUID
                $uuid = Uuid::fromHex($frame->uuid);

                // Assert ActivityInterface is Activity for FrameFactory
                if (!($activity instanceof \Tcrawf\Zebra\Activity\Activity)) {
                    throw new \RuntimeException('Activity must be an instance of Activity');
                }

                // Recreate frame with edited data, preserving UUID
                // Issue keys will be automatically extracted from description
                // For current frame: if stop time is provided, frame becomes completed; otherwise stays active
                $updatedFrame = FrameFactory::create(
                    $startTime->utc(),
                    $stopTime?->utc(),
                    $activity,
                    $isIndividual,
                    $role,
                    $editedData['description'] ?? $frame->description,
                    null, // updatedAt - will default to now
                    $uuid
                );

                // Save the frame: use saveCurrent() for active frames, update() for completed frames
                if ($isCurrentFrame && $updatedFrame->isActive()) {
                    // Current frame remains active - update it as current
                    $this->frameRepository->saveCurrent($updatedFrame);
                } elseif ($isCurrentFrame && !$updatedFrame->isActive()) {
                    // Current frame is being stopped - save as completed and clear current
                    $this->frameRepository->save($updatedFrame);
                    $this->frameRepository->clearCurrent();
                } else {
                    // Completed frame - just update it
                    $this->frameRepository->update($updatedFrame);
                }

                $io->writeln('<info>Frame updated successfully.</info>');

                return Command::SUCCESS;
            } catch (\JsonException $e) {
                $io->writeln(
                    '<error>Error while parsing inputted values: Invalid JSON: ' . $e->getMessage() . '</error>'
                );
                $this->pauseForUser($input, $io);
                $text = $editedJson; // Use edited text for next iteration
            } catch (\RuntimeException $e) {
                $io->writeln('<error>Error while parsing inputted values: ' . $e->getMessage() . '</error>');
                $this->pauseForUser($input, $io);
                $text = $editedJson; // Use edited text for next iteration
            } catch (\Exception $e) {
                $io->writeln('<error>Error while parsing inputted values: ' . $e->getMessage() . '</error>');
                $this->pauseForUser($input, $io);
                $text = $editedJson; // Use edited text for next iteration
            }
        }
    }

    /**
     * Resolve a frame by UUID or negative index.
     * Returns array with 'frame' and 'isCurrent' keys, or null if not found.
     *
     * @param string|null $identifier Frame UUID or negative index (-1, -2, etc.)
     * @return array{frame: FrameInterface, isCurrent: bool}|null
     */
    private function resolveFrame(?string $identifier): ?array
    {
        // If no identifier provided, check for current frame first (like watson)
        if ($identifier === null) {
            $currentFrame = $this->frameRepository->getCurrent();
            if ($currentFrame !== null) {
                return ['frame' => $currentFrame, 'isCurrent' => true];
            }
            // No current frame, default to last completed frame
            $identifier = '-1';
        }

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
                return ['frame' => $completedFramesArray[$arrayIndex], 'isCurrent' => false];
            }

            return null;
        }

        // Try as UUID - check if it's the current frame
        $frame = $this->frameRepository->get($identifier);
        if ($frame === null) {
            return null;
        }

        $currentFrame = $this->frameRepository->getCurrent();
        $isCurrent = $currentFrame !== null && $currentFrame->uuid === $frame->uuid;

        return ['frame' => $frame, 'isCurrent' => $isCurrent];
    }

    /**
     * Validate and sanitize editor command to prevent command injection and path traversal.
     *
     * @param string $editor
     * @return string|null Returns validated editor command or null if invalid
     */
    /**
     * Validate editor command to prevent command injection and path traversal.
     *
     * Since we use proc_open() with array syntax, we bypass the shell entirely,
     * so we only need to validate the path itself, not shell metacharacters.
     *
     * @param string $editor
     * @return string|null Returns validated editor path or null if invalid
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
            // Manually resolve .. components to prevent directory traversal
            $normalizedPath = $this->normalizePath($editor);
            if ($normalizedPath === null || str_contains($normalizedPath, '..')) {
                return null;
            }
            $editor = $normalizedPath;
        }

        // Final check: ensure resolved path doesn't contain ..
        if (str_contains($editor, '..')) {
            return null;
        }

        // Prevent protocol-like strings (multiple slashes at start on Unix)
        if (PHP_OS_FAMILY !== 'Windows' && str_starts_with($editor, '//')) {
            return null;
        }

        return $editor;
    }

    /**
     * Normalize an absolute path by resolving .. and . components without requiring the path to exist.
     * This prevents directory traversal attacks.
     *
     * @param string $path Must be an absolute path
     * @return string|null Normalized path or null if invalid
     */
    private function normalizePath(string $path): ?string
    {
        // Normalize separators
        $normalized = str_replace('\\', '/', $path);

        // Extract Windows drive letter if present
        $driveLetter = '';
        if (PHP_OS_FAMILY === 'Windows' && preg_match('/^([A-Z]:)/i', $normalized, $matches)) {
            $driveLetter = $matches[1];
            $normalized = substr($normalized, 2); // Remove drive letter
        }

        // Split by directory separator
        $components = explode('/', $normalized);
        $parts = [];

        foreach ($components as $component) {
            if ($component === '' || $component === '.') {
                // Skip empty components and current directory references
                continue;
            }

            if ($component === '..') {
                // Go up one directory
                if (empty($parts)) {
                    // Can't go above root - this is a traversal attempt
                    return null;
                }
                // Remove last component (go up one level)
                array_pop($parts);
            } else {
                $parts[] = $component;
            }
        }

        // Reconstruct path
        $result = implode('/', $parts);

        // Restore absolute path prefix
        if ($driveLetter !== '') {
            $result = $driveLetter . '/' . $result;
        } else {
            $result = '/' . $result;
        }

        return $result;
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
            $validated = $this->validateEditorCommand($editor);
            if ($validated !== null) {
                return $validated;
            }
            // Invalid editor from option, fall through to defaults
        }

        // Try environment variables
        $editor = getenv('VISUAL');
        if ($editor !== false) {
            $validated = $this->validateEditorCommand($editor);
            if ($validated !== null) {
                return $validated;
            }
        }

        $editor = getenv('EDITOR');
        if ($editor !== false) {
            $validated = $this->validateEditorCommand($editor);
            if ($validated !== null) {
                return $validated;
            }
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

        $tmpFile = tempnam(sys_get_temp_dir(), 'zebra_edit_');
        if ($tmpFile === false) {
            return null;
        }

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

        // Wait for the editor process to complete
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
     * Pause and wait for user to press Enter (equivalent to click.pause()).
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
        if ($input->mustSuggestArgumentValuesFor('frame')) {
            $this->autocompletion->suggest($input, $suggestions);
        }
    }
}

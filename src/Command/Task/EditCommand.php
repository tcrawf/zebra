<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command\Task;

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
use Tcrawf\Zebra\Command\Autocompletion\TaskAutocompletion;
use Tcrawf\Zebra\Command\PhpUnitDetectionTrait;
use Tcrawf\Zebra\Command\Trait\ActivityResolutionTrait;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\EntityKey\EntitySource;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;
use Tcrawf\Zebra\Task\TaskFactory;
use Tcrawf\Zebra\Task\TaskInterface;
use Tcrawf\Zebra\Task\TaskRepositoryInterface;
use Tcrawf\Zebra\Task\TaskStatus;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;

class EditCommand extends Command
{
    use ActivityResolutionTrait;
    use PhpUnitDetectionTrait;

    public function __construct(
        private readonly TaskRepositoryInterface $taskRepository,
        private readonly ActivityRepositoryInterface $activityRepository,
        private readonly ProjectRepositoryInterface $projectRepository,
        private readonly TaskAutocompletion $autocompletion,
        private readonly TimezoneFormatter $timezoneFormatter
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('task:edit')
            ->setDescription('Edit an existing task')
            ->addArgument(
                'uuid',
                InputArgument::REQUIRED,
                'Task UUID'
            )
            ->addOption(
                'summary',
                null,
                InputOption::VALUE_REQUIRED,
                'New task summary'
            )
            ->addOption(
                'activity',
                'a',
                InputOption::VALUE_REQUIRED,
                'Activity alias or ID to associate with the task (use empty string to remove)'
            )
            ->addOption(
                'due',
                'd',
                InputOption::VALUE_REQUIRED,
                'Due date (ISO 8601 format, use empty string to remove)'
            )
            ->addOption(
                'issue-tag',
                'i',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Issue tag (can be specified multiple times, replaces existing tags)'
            )
            ->addOption(
                'status',
                's',
                InputOption::VALUE_REQUIRED,
                'Status (open, in-progress). Cannot set to completed via edit.'
            )
            ->addOption('editor', null, InputOption::VALUE_OPTIONAL, 'Editor command (default: $EDITOR or $VISUAL)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $uuid = $input->getArgument('uuid');

        // Get existing task
        $task = $this->taskRepository->get($uuid);
        if ($task === null) {
            $io->error("Task with UUID '{$uuid}' not found");
            return Command::FAILURE;
        }

        // Cannot edit complete tasks
        if ($task->status === TaskStatus::Complete) {
            $io->error('Cannot edit a complete task');
            return Command::FAILURE;
        }

        // Get new values from options
        $summary = $input->getOption('summary');
        $activityIdentifier = $input->getOption('activity');
        $dueStr = $input->getOption('due');
        $issueTags = $input->getOption('issue-tag');
        $statusStr = $input->getOption('status');

        // Check if any editing options were provided (excluding --editor which is just for specifying the editor)
        $hasEditingOptions = ($summary !== null) || ($activityIdentifier !== null) || ($dueStr !== null)
            || !empty($issueTags) || ($statusStr !== null);

        // If no editing options provided, open JSON editor
        if (!$hasEditingOptions) {
            return $this->editInJsonEditor($task, $input, $io);
        }

        // Update summary
        $summaryChanged = false;
        if ($summary !== null) {
            if (trim($summary) === '') {
                $io->error('Task summary cannot be empty');
                return Command::FAILURE;
            }
            $summaryChanged = ($summary !== $task->summary);
        } else {
            $summary = $task->summary;
        }

        // Update activity
        $activity = $task->activity;
        if ($activityIdentifier !== null) {
            if ($activityIdentifier === '') {
                $activity = null;
            } else {
                $activity = $this->resolveActivity($activityIdentifier, $io);
                if ($activity === null) {
                    $io->error("Activity '{$activityIdentifier}' not found");
                    return Command::FAILURE;
                }
            }
        }

        // Update due date
        $dueAt = $task->dueAt;
        if ($dueStr !== null) {
            if ($dueStr === '') {
                $dueAt = null;
            } else {
                try {
                    $dueAt = Carbon::parse($dueStr);
                } catch (\Exception $e) {
                    $io->error("Invalid due date format: {$dueStr}. Use ISO 8601 format.");
                    return Command::FAILURE;
                }
            }
        }

        // Update issue tags
        if (!empty($issueTags)) {
            // If tags were explicitly provided, use them
            $finalIssueTags = $issueTags;
        } else {
            // Start with existing tags
            $finalIssueTags = $task->issueTags;
        }

        // If summary changed, extract tags from new summary and merge with existing tags
        if ($summaryChanged) {
            $extractedTags = $this->extractIssueTags($summary);
            // Merge extracted tags with existing tags (remove duplicates)
            $finalIssueTags = array_values(array_unique(array_merge($finalIssueTags, $extractedTags)));
        }

        $issueTags = $finalIssueTags;

        // Update status
        $status = $task->status;
        if ($statusStr !== null) {
            try {
                $status = TaskStatus::from($statusStr);
                if ($status === TaskStatus::Complete) {
                    $io->error('Cannot set status to complete via edit. Use task:complete to complete a task.');
                    return Command::FAILURE;
                }
            } catch (\ValueError $e) {
                $io->error("Invalid status '{$statusStr}'. Valid values: open, in-progress");
                return Command::FAILURE;
            }
        }

        try {
            // Create updated task
            $updatedTask = TaskFactory::create(
                $summary,
                $task->createdAt,
                $dueAt,
                null, // completedAt - cannot be set via edit
                $activity,
                $issueTags,
                $status,
                $task->completionNote,
                \Tcrawf\Zebra\Uuid\Uuid::fromHex($task->uuid)
            );

            $this->taskRepository->update($updatedTask);

            $io->success('Task updated successfully');
            $io->writeln(sprintf('UUID: %s', $updatedTask->uuid));
            $io->writeln(sprintf('Summary: %s', $updatedTask->summary));
            $io->writeln(sprintf('Status: %s', $updatedTask->status->value));
            if (!empty($updatedTask->issueTags)) {
                $io->writeln(sprintf('Issue tags: %s', implode(', ', $updatedTask->issueTags)));
            }
            if ($updatedTask->activity !== null) {
                $io->writeln(sprintf('Activity: %s', $updatedTask->activity->name));
            }
            if ($updatedTask->dueAt !== null) {
                $io->writeln(sprintf('Due: %s', $updatedTask->dueAt->toDateTimeString()));
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('An error occurred: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function editInJsonEditor(TaskInterface $task, InputInterface $input, SymfonyStyle $io): int
    {
        // Create JSON template in local timezone format
        $dateFormat = 'Y-m-d H:i:s';
        $createdAtLocal = $this->timezoneFormatter->toLocal($task->createdAt);
        $dueAtLocal = $task->dueAt !== null ? $this->timezoneFormatter->toLocal($task->dueAt) : null;
        $completedAtLocal = $task->completedAt !== null ? $this->timezoneFormatter->toLocal($task->completedAt) : null;

        // Use alias if available, otherwise use ID
        $activityIdentifier = $task->activity !== null
            ? ($task->activity->alias ?? $task->activity->entityKey->toString())
            : null;

        $data = [
            'summary' => $task->summary,
            'status' => $task->status->value,
        ];

        if ($task->activity !== null) {
            $data['activity'] = [
                'key' => [
                    'source' => $task->activity->entityKey->source->value,
                    'id' => $activityIdentifier,
                ],
            ];
        } else {
            $data['activity'] = null;
        }

        if ($dueAtLocal !== null) {
            $data['due'] = $dueAtLocal->format($dateFormat);
        } else {
            $data['due'] = null;
        }

        $data['issueTags'] = $task->issueTags;

        if ($task->completionNote !== '') {
            $data['completionNote'] = $task->completionNote;
        }

        $jsonTemplate = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Open in editor with retry loop
        $editor = $this->getEditor($input);
        $text = $jsonTemplate;

        while (true) {
            $editedJson = $this->openInEditor($text, $editor);

            // Check if editor failed to open
            if ($editedJson === null) {
                $io->error(
                    'Failed to open editor. Please ensure you are in an interactive terminal ' .
                    'and have a valid editor configured ($EDITOR or $VISUAL).'
                );
                return Command::FAILURE;
            }

            // User made no changes
            if ($editedJson === $text) {
                $io->info('No changes made.');
                return Command::SUCCESS;
            }

            try {
                $editedData = json_decode($editedJson, true, 512, JSON_THROW_ON_ERROR);

                // Validate required fields
                if (!isset($editedData['summary'])) {
                    throw new \RuntimeException('Edited task must contain summary key.');
                }

                if (trim($editedData['summary']) === '') {
                    throw new \RuntimeException('Task summary cannot be empty.');
                }

                // Parse summary
                $summary = $editedData['summary'];
                $summaryChanged = ($summary !== $task->summary);

                // Parse activity
                $activity = $task->activity;
                if (array_key_exists('activity', $editedData)) {
                    if ($editedData['activity'] === null) {
                        $activity = null;
                    } elseif (isset($editedData['activity']['key'])) {
                        $identifier = $editedData['activity']['key']['id'];
                        $source = EntitySource::from($editedData['activity']['key']['source']);

                        // Try to resolve by alias first
                        $newActivity = $this->activityRepository->getByAlias($identifier);

                        if ($newActivity === null) {
                            // Not found by alias, try as ID
                            if (ctype_digit($identifier)) {
                                $entityKey = new EntityKey($source, (int) $identifier);
                            } else {
                                $entityKey = EntityKey::local($identifier);
                            }
                            $newActivity = $this->activityRepository->get($entityKey);
                        }

                        if ($newActivity === null) {
                            throw new \RuntimeException("Activity '{$identifier}' not found.");
                        }

                        $activity = $newActivity;
                    }
                }

                // Parse due date
                $dueAt = $task->dueAt;
                if (array_key_exists('due', $editedData)) {
                    if ($editedData['due'] === null) {
                        $dueAt = null;
                    } else {
                        try {
                            $dueAt = Carbon::parse($editedData['due']);
                        } catch (\Exception $e) {
                            throw new \RuntimeException("Invalid due date format: {$editedData['due']}");
                        }
                    }
                }

                // Parse issue tags
                $issueTags = $task->issueTags;
                if (isset($editedData['issueTags']) && is_array($editedData['issueTags'])) {
                    $issueTags = $editedData['issueTags'];
                }

                // If summary changed, extract tags from new summary and merge with existing tags
                if ($summaryChanged) {
                    $extractedTags = $this->extractIssueTags($summary);
                    // Merge extracted tags with existing tags (remove duplicates)
                    $issueTags = array_values(array_unique(array_merge($issueTags, $extractedTags)));
                }

                // Parse status
                $status = $task->status;
                if (isset($editedData['status'])) {
                    try {
                        $status = TaskStatus::from($editedData['status']);
                        if ($status === TaskStatus::Complete) {
                            throw new \RuntimeException(
                                'Cannot set status to complete via edit. Use task:complete to complete a task.'
                            );
                        }
                    } catch (\ValueError $e) {
                        throw new \RuntimeException(
                            "Invalid status '{$editedData['status']}'. Valid values: open, in-progress"
                        );
                    }
                }

                // Parse completion note
                $completionNote = $task->completionNote;
                if (isset($editedData['completionNote'])) {
                    $completionNote = $editedData['completionNote'];
                }

                // Create updated task
                $updatedTask = TaskFactory::create(
                    $summary,
                    $task->createdAt,
                    $dueAt,
                    null, // completedAt - cannot be set via edit
                    $activity,
                    $issueTags,
                    $status,
                    $completionNote,
                    \Tcrawf\Zebra\Uuid\Uuid::fromHex($task->uuid)
                );

                $this->taskRepository->update($updatedTask);

                $io->success('Task updated successfully');
                $io->writeln(sprintf('UUID: %s', $updatedTask->uuid));
                $io->writeln(sprintf('Summary: %s', $updatedTask->summary));
                $io->writeln(sprintf('Status: %s', $updatedTask->status->value));
                if (!empty($updatedTask->issueTags)) {
                    $io->writeln(sprintf('Issue tags: %s', implode(', ', $updatedTask->issueTags)));
                }
                if ($updatedTask->activity !== null) {
                    $io->writeln(sprintf('Activity: %s', $updatedTask->activity->name));
                }
                if ($updatedTask->dueAt !== null) {
                    $io->writeln(sprintf('Due: %s', $updatedTask->dueAt->toDateTimeString()));
                }

                return Command::SUCCESS;
            } catch (\JsonException $e) {
                $io->writeln('<fg=red>Invalid JSON: ' . $e->getMessage() . '</fg=red>');
                $retry = $io->confirm('Would you like to edit again?', true);
                if (!$retry) {
                    return Command::FAILURE;
                }
                $text = $editedJson; // Use the invalid JSON as starting point for retry
                continue;
            } catch (\RuntimeException $e) {
                $io->writeln('<fg=red>' . $e->getMessage() . '</fg=red>');
                $retry = $io->confirm('Would you like to edit again?', true);
                if (!$retry) {
                    return Command::FAILURE;
                }
                $text = $editedJson; // Use the edited JSON as starting point for retry
                continue;
            } catch (\Exception $e) {
                $io->writeln('<fg=red>An error occurred: ' . $e->getMessage() . '</fg=red>');
                $retry = $io->confirm('Would you like to edit again?', true);
                if (!$retry) {
                    return Command::FAILURE;
                }
                $text = $editedJson; // Use the edited JSON as starting point for retry
                continue;
            }
        }
    }

    /**
     * Get activity repository instance.
     */
    protected function getActivityRepository(): ActivityRepositoryInterface
    {
        return $this->activityRepository;
    }

    /**
     * Get project repository instance.
     */
    protected function getProjectRepository(): ProjectRepositoryInterface
    {
        return $this->projectRepository;
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
     * Validate editor command to prevent command injection and path traversal.
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
            $normalized = substr($normalized, 2);
        }

        // Split by directory separator
        $components = explode('/', $normalized);
        $parts = [];

        foreach ($components as $component) {
            if ($component === '' || $component === '.') {
                continue;
            }

            if ($component === '..') {
                if (empty($parts)) {
                    return null;
                }
                array_pop($parts);
            } else {
                $parts[] = $component;
            }
        }

        // Reconstruct path
        $result = implode('/', $parts);

        if ($driveLetter !== '') {
            $result = $driveLetter . '/' . $result;
        } else {
            $result = '/' . $result;
        }

        return $result;
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
        $tmpFile = sys_get_temp_dir() . '/zebra_task_edit_' . uniqid('', true) . '.json';
        file_put_contents($tmpFile, $content);

        // Open editor using proc_open for better control over process handling
        if ($isTestScript) {
            $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
            $descriptorspec = [
                0 => ['file', $nullDevice, 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
        } else {
            $descriptorspec = [
                0 => STDIN,
                1 => STDOUT,
                2 => STDERR,
            ];
        }

        $process = @proc_open([$validatedEditor, $tmpFile], $descriptorspec, $pipes);

        if (!is_resource($process)) {
            unlink($tmpFile);
            return null;
        }

        // Close any pipes we're not using
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                if ($isTestScript) {
                    stream_set_blocking($pipe, false);
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
     * Extract issue tags from summary.
     * Issue tags have the format: 2-6 uppercase letters, hyphen, 1-5 digits (e.g., AA-1234, ABC-12345).
     *
     * @param string $summary The summary to extract issue tags from
     * @return array Array of issue tags found in the summary
     */
    private function extractIssueTags(string $summary): array
    {
        if (empty($summary)) {
            return [];
        }

        // Pattern: 2-6 uppercase letters, hyphen, 1-5 digits
        $pattern = '/[A-Z]{2,6}-\d{1,5}/';
        preg_match_all($pattern, $summary, $matches);

        // Return unique issue tags
        // preg_match_all always populates $matches[0], even if empty
        return array_values(array_unique($matches[0]));
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('uuid')) {
            $this->autocompletion->suggest($input, $suggestions);
        }
    }
}

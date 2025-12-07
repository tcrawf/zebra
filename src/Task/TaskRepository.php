<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Task;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;

/**
 * Repository for storing and retrieving tasks.
 * Uses JSON file storage to persist tasks.
 */
class TaskRepository implements TaskRepositoryInterface
{
    private const string DEFAULT_STORAGE_FILENAME = 'tasks.json';
    private readonly string $storageFilename;

    /**
     * @param TaskFileStorageFactoryInterface $storageFactory
     * @param string $storageFilename The storage filename (defaults to 'tasks.json')
     */
    public function __construct(
        private readonly TaskFileStorageFactoryInterface $storageFactory,
        string $storageFilename = self::DEFAULT_STORAGE_FILENAME
    ) {
        $this->storageFilename = $storageFilename;
    }

    /**
     * Save a task to storage.
     *
     * @param TaskInterface $task
     * @return void
     */
    public function save(TaskInterface $task): void
    {
        $tasks = $this->loadFromStorage();

        // Store task by UUID (will overwrite if UUID already exists)
        $tasks[$task->uuid] = $task->toArray();

        $this->saveToStorage($tasks);
    }

    /**
     * Get all tasks from storage.
     *
     * @return array<TaskInterface>
     */
    public function all(): array
    {
        $tasksData = $this->loadFromStorage();
        $tasks = [];

        foreach ($tasksData as $taskData) {
            try {
                $tasks[] = TaskFactory::fromArray($taskData);
            } catch (\Exception $e) {
                // Skip tasks that cannot be deserialized
                continue;
            }
        }

        return $tasks;
    }

    /**
     * Get a task by its UUID.
     *
     * @param string $uuid
     * @return TaskInterface|null
     */
    public function get(string $uuid): ?TaskInterface
    {
        $tasksData = $this->loadFromStorage();

        if (!isset($tasksData[$uuid])) {
            return null;
        }

        try {
            return TaskFactory::fromArray($tasksData[$uuid]);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Update an existing task.
     *
     * @param TaskInterface $task
     * @return void
     */
    public function update(TaskInterface $task): void
    {
        $tasks = $this->loadFromStorage();

        // Check if task exists
        if (!isset($tasks[$task->uuid])) {
            throw new InvalidArgumentException(
                "Cannot update task: task with UUID '{$task->uuid}' does not exist."
            );
        }

        // Update the task
        $tasks[$task->uuid] = $task->toArray();

        $this->saveToStorage($tasks);
    }

    /**
     * Remove a task by UUID.
     *
     * @param string $uuid
     * @return void
     */
    public function remove(string $uuid): void
    {
        $tasks = $this->loadFromStorage();

        // Check if task exists
        if (!isset($tasks[$uuid])) {
            throw new InvalidArgumentException(
                "Cannot remove task: task with UUID '{$uuid}' does not exist."
            );
        }

        // Remove the task
        unset($tasks[$uuid]);

        $this->saveToStorage($tasks);
    }

    /**
     * Filter tasks based on multiple criteria.
     *
     * @param TaskStatus|null $status Filter by status
     * @param array<string>|null $issueTags Filter by issue tags
     * @param CarbonInterface|int|string|null $dueBefore Filter by due date before (inclusive)
     * @param CarbonInterface|int|string|null $dueAfter Filter by due date after (inclusive)
     * @return array<TaskInterface>
     */
    public function filter(
        ?TaskStatus $status = null,
        ?array $issueTags = null,
        CarbonInterface|int|string|null $dueBefore = null,
        CarbonInterface|int|string|null $dueAfter = null
    ): array {
        $allTasks = $this->all();
        $filteredTasks = [];

        // Convert date range to Carbon if provided
        $dueBeforeTime = $dueBefore !== null ? $this->convertToCarbon($dueBefore)->utc() : null;
        $dueAfterTime = $dueAfter !== null ? $this->convertToCarbon($dueAfter)->utc() : null;

        foreach ($allTasks as $task) {
            try {
                // Filter by status
                if ($status !== null && $task->status !== $status) {
                    continue;
                }

                // Filter by issue tags (task must have at least one matching issue tag)
                if (!empty($issueTags)) {
                    $hasMatchingTag = false;
                    foreach ($issueTags as $issueTag) {
                        if (in_array($issueTag, $task->issueTags, true)) {
                            $hasMatchingTag = true;
                            break;
                        }
                    }
                    if (!$hasMatchingTag) {
                        continue;
                    }
                }

                // Filter by due date
                if ($dueBeforeTime !== null || $dueAfterTime !== null) {
                    $taskDueAt = $task->dueAt;

                    // Skip tasks without due date if filtering by due date
                    if ($taskDueAt === null) {
                        continue;
                    }

                    $taskDueAtUtc = $taskDueAt->utc();

                    // Filter by dueBefore (task due date must be at or before dueBeforeTime)
                    if ($dueBeforeTime !== null && $taskDueAtUtc->gt($dueBeforeTime)) {
                        continue;
                    }

                    // Filter by dueAfter (task due date must be at or after dueAfterTime)
                    if ($dueAfterTime !== null && $taskDueAtUtc->lt($dueAfterTime)) {
                        continue;
                    }
                }

                $filteredTasks[] = $task;
            } catch (\Exception $e) {
                // Skip tasks that cannot be processed
                continue;
            }
        }

        return $filteredTasks;
    }

    /**
     * Convert a time value to a CarbonInterface instance.
     * Strings are parsed in the local/system timezone, then converted to UTC.
     *
     * @param CarbonInterface|int|string $value
     * @return CarbonInterface
     */
    private function convertToCarbon(CarbonInterface|int|string $value): CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if (is_int($value)) {
            return Carbon::createFromTimestamp($value);
        }

        // Parse string in local timezone, then convert to UTC
        // This ensures strings without timezone info are interpreted in user's local timezone
        static $timezoneFormatter = null;
        if ($timezoneFormatter === null) {
            $timezoneFormatter = new TimezoneFormatter();
        }
        return $timezoneFormatter->parseLocalToUtc($value);
    }

    /**
     * Load tasks from storage file.
     * Returns tasks as an associative array keyed by UUID.
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadFromStorage(): array
    {
        $storage = $this->storageFactory->create($this->storageFilename);
        $data = $storage->read();

        if (empty($data)) {
            return [];
        }

        return $data;
    }

    /**
     * Save tasks to storage file.
     *
     * @param array<string, array<string, mixed>> $tasks
     * @return void
     */
    private function saveToStorage(array $tasks): void
    {
        $storage = $this->storageFactory->create($this->storageFilename);
        $storage->write($tasks);
    }
}

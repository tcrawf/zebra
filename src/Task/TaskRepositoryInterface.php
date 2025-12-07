<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Task;

use Carbon\CarbonInterface;

/**
 * Interface for task repository.
 * Defines the contract for storing and retrieving tasks.
 */
interface TaskRepositoryInterface
{
    /**
     * Save a task to storage.
     *
     * @param TaskInterface $task
     * @return void
     */
    public function save(TaskInterface $task): void;

    /**
     * Get all tasks from storage.
     *
     * @return array<TaskInterface>
     */
    public function all(): array;

    /**
     * Get a task by its UUID.
     *
     * @param string $uuid
     * @return TaskInterface|null
     */
    public function get(string $uuid): ?TaskInterface;

    /**
     * Update an existing task.
     *
     * @param TaskInterface $task
     * @return void
     */
    public function update(TaskInterface $task): void;

    /**
     * Remove a task by UUID.
     *
     * @param string $uuid
     * @return void
     */
    public function remove(string $uuid): void;

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
    ): array;
}

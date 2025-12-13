<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Frame;

/**
 * Service for migrating frames from old format (denormalized activity data)
 * to new format (normalized activity key only).
 */
class FrameMigrationService
{
    private const string DEFAULT_STORAGE_FILENAME = 'frames.json';
    private const string CURRENT_FRAME_FILENAME = 'current_frame.json';

    private readonly string $storageFilename;

    /**
     * @param FrameFileStorageFactoryInterface $storageFactory
     * @param string $storageFilename The storage filename (defaults to 'frames.json')
     */
    public function __construct(
        private readonly FrameFileStorageFactoryInterface $storageFactory,
        string $storageFilename = self::DEFAULT_STORAGE_FILENAME
    ) {
        $this->storageFilename = $storageFilename;
    }

    /**
     * Check if any frames use the old format (denormalized activity data).
     *
     * @return bool True if migration is needed, false otherwise
     */
    public function needsMigration(): bool
    {
        $framesData = $this->loadFramesFromStorage();
        $currentFrameData = $this->loadCurrentFrameFromStorage();

        // Check completed frames
        foreach ($framesData as $frameData) {
            if ($this->isOldFormat($frameData)) {
                return true;
            }
        }

        // Check current frame
        if ($currentFrameData !== null && $this->isOldFormat($currentFrameData)) {
            return true;
        }

        return false;
    }

    /**
     * Migrate all frames from old format to new format.
     * Rewrites storage files with normalized format.
     *
     * @return int Number of frames migrated
     */
    public function migrateFrames(): int
    {
        $framesData = $this->loadFramesFromStorage();
        $currentFrameData = $this->loadCurrentFrameFromStorage();
        $migratedCount = 0;

        // Migrate completed frames
        $migratedFrames = [];
        foreach ($framesData as $uuid => $frameData) {
            if ($this->isOldFormat($frameData)) {
                $migratedFrames[$uuid] = $this->migrateFrame($frameData);
                $migratedCount++;
            } else {
                $migratedFrames[$uuid] = $frameData;
            }
        }

        // Migrate current frame
        $migratedCurrentFrame = null;
        if ($currentFrameData !== null) {
            if ($this->isOldFormat($currentFrameData)) {
                $migratedCurrentFrame = $this->migrateFrame($currentFrameData);
                $migratedCount++;
            } else {
                $migratedCurrentFrame = $currentFrameData;
            }
        }

        // Save migrated frames back to storage
        $this->saveFramesToStorage($migratedFrames);
        $this->saveCurrentFrameToStorage($migratedCurrentFrame);

        return $migratedCount;
    }

    /**
     * Migrate a single frame from old format to new format.
     *
     * @param array<string, mixed> $frameData
     * @return array<string, mixed> Migrated frame data
     */
    public function migrateFrame(array $frameData): array
    {
        if (!$this->isOldFormat($frameData)) {
            return $frameData; // Already in new format
        }

        $activityData = $frameData['activity'];
        if (!is_array($activityData)) {
            return $frameData; // Invalid format, return as-is
        }

        // Extract activity key from old format
        if (!isset($activityData['key'])) {
            // Very old format without key - not supported
            throw new \RuntimeException(
                'Cannot migrate frame: activity key not found. ' .
                'Very old format without activity.key is no longer supported.'
            );
        }

        // Create normalized format: only keep the activity key
        $migratedFrame = $frameData;
        $migratedFrame['activity'] = [
            'key' => $activityData['key'],
        ];

        // Normalize role: convert from old format (role object) to new format (roleId)
        if (isset($migratedFrame['role'])) {
            if (is_array($migratedFrame['role']) && isset($migratedFrame['role']['id'])) {
                // Old format: role is an array/object with 'id'
                $migratedFrame['roleId'] = (int) $migratedFrame['role']['id'];
                unset($migratedFrame['role']);
            } elseif ($migratedFrame['role'] === null) {
                // Null role: remove 'role' key, ensure 'roleId' is null
                $migratedFrame['roleId'] = null;
                unset($migratedFrame['role']);
            } else {
                // Invalid format: remove 'role' key, set 'roleId' to null
                $migratedFrame['roleId'] = null;
                unset($migratedFrame['role']);
            }
        } elseif (!isset($migratedFrame['roleId'])) {
            // No role data at all: set roleId to null (for individual frames)
            $migratedFrame['roleId'] = null;
        }

        return $migratedFrame;
    }

    /**
     * Check if frame data uses old format (has denormalized activity data or role object).
     *
     * @param array<string, mixed> $frameData
     * @return bool True if old format, false if new format
     */
    private function isOldFormat(array $frameData): bool
    {
        // Check for old activity format
        if (isset($frameData['activity']) && is_array($frameData['activity'])) {
            $activityData = $frameData['activity'];

            // Old format has: name, desc, project, alias keys (in addition to key)
            // New format has: only key
            $hasOldActivityFormat = isset($activityData['name'])
                || isset($activityData['desc'])
                || isset($activityData['project'])
                || isset($activityData['alias']);

            if ($hasOldActivityFormat) {
                return true;
            }
        }

        // Check for old role format
        // Old format has: 'role' as object/array with id, name, etc.
        // New format has: 'roleId' as integer or null
        if (isset($frameData['role']) && is_array($frameData['role'])) {
            // Old format: role is an object/array
            return true;
        }
        if (isset($frameData['role']) && !isset($frameData['roleId'])) {
            // Has 'role' but not 'roleId' - old format
            return true;
        }
        // If has both 'role' and 'roleId', it's still old format (should only have 'roleId')
        if (isset($frameData['role']) && isset($frameData['roleId'])) {
            return true;
        }

        // New format: has 'roleId' (or neither 'role' nor 'roleId' for null roles)
        return false;
    }

    /**
     * Load frames from storage file.
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadFramesFromStorage(): array
    {
        $storage = $this->storageFactory->create($this->storageFilename);
        $data = $storage->read();

        if (empty($data)) {
            return [];
        }

        return $data;
    }

    /**
     * Load current frame from storage file.
     *
     * @return array<string, mixed>|null
     */
    private function loadCurrentFrameFromStorage(): ?array
    {
        $storage = $this->storageFactory->create(self::CURRENT_FRAME_FILENAME);
        $data = $storage->read();

        if (empty($data)) {
            return null;
        }

        return $data;
    }

    /**
     * Save frames to storage file.
     *
     * @param array<string, array<string, mixed>> $frames
     * @return void
     */
    private function saveFramesToStorage(array $frames): void
    {
        $storage = $this->storageFactory->create($this->storageFilename);
        $storage->write($frames);
    }

    /**
     * Save current frame to storage file.
     *
     * @param array<string, mixed>|null $frameData
     * @return void
     */
    private function saveCurrentFrameToStorage(?array $frameData): void
    {
        $storage = $this->storageFactory->create(self::CURRENT_FRAME_FILENAME);
        if ($frameData === null) {
            $storage->write([]);
        } else {
            $storage->write($frameData);
        }
    }
}

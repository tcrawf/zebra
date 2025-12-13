<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Frame;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\FileStorage\FileStorageInterface;
use Tcrawf\Zebra\Frame\FrameFileStorageFactoryInterface;
use Tcrawf\Zebra\Frame\FrameMigrationService;

class FrameMigrationServiceTest extends TestCase
{
    private FrameFileStorageFactoryInterface&MockObject $storageFactory;
    private FileStorageInterface&MockObject $framesStorage;
    private FileStorageInterface&MockObject $currentFrameStorage;
    private FrameMigrationService $migrationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->framesStorage = $this->createMock(FileStorageInterface::class);
        $this->currentFrameStorage = $this->createMock(FileStorageInterface::class);

        $this->storageFactory = $this->createMock(FrameFileStorageFactoryInterface::class);
        $this->storageFactory
            ->method('create')
            ->willReturnCallback(function ($filename) {
                if ($filename === 'frames.json') {
                    return $this->framesStorage;
                }
                if ($filename === 'current_frame.json') {
                    return $this->currentFrameStorage;
                }
                return $this->framesStorage;
            });

        $this->migrationService = new FrameMigrationService($this->storageFactory);
    }

    public function testNeedsMigrationReturnsFalseWhenNoFrames(): void
    {
        $this->framesStorage->method('read')->willReturn([]);
        $this->currentFrameStorage->method('read')->willReturn([]);

        $this->assertFalse($this->migrationService->needsMigration());
    }

    public function testNeedsMigrationReturnsFalseWhenAllFramesNewFormat(): void
    {
        $framesData = [
            'uuid1' => [
                'uuid' => 'uuid1',
                'start' => time() - 3600,
                'stop' => time(),
                'activity' => [
                    'key' => [
                        'source' => 'zebra',
                        'id' => '123',
                    ],
                ],
                'isIndividual' => false,
                'roleId' => 1, // New format: only roleId
                'desc' => 'Description',
                'updatedAt' => time(),
            ],
        ];

        $this->framesStorage->method('read')->willReturn($framesData);
        $this->currentFrameStorage->method('read')->willReturn([]);

        $this->assertFalse($this->migrationService->needsMigration());
    }

    public function testNeedsMigrationReturnsTrueWhenOldFormatFramesExist(): void
    {
        $framesData = [
            'uuid1' => [
                'uuid' => 'uuid1',
                'start' => time() - 3600,
                'stop' => time(),
                'activity' => [
                    'key' => [
                        'source' => 'zebra',
                        'id' => '123',
                    ],
                    'name' => 'Test Activity', // Old format
                    'desc' => 'Description', // Old format
                    'project' => [ // Old format
                        'source' => 'zebra',
                        'id' => '100',
                    ],
                    'alias' => 'test', // Old format
                ],
                'isIndividual' => false,
                'role' => ['id' => 1, 'name' => 'Developer'],
                'desc' => 'Description',
                'updatedAt' => time(),
            ],
        ];

        $this->framesStorage->method('read')->willReturn($framesData);
        $this->currentFrameStorage->method('read')->willReturn([]);

        $this->assertTrue($this->migrationService->needsMigration());
    }

    public function testMigrateFramesConvertsOldFormatToNewFormat(): void
    {
        $oldFormatFrame = [
            'uuid' => 'uuid1',
            'start' => time() - 3600,
            'stop' => time(),
            'activity' => [
                'key' => [
                    'source' => 'zebra',
                    'id' => '123',
                ],
                'name' => 'Test Activity',
                'desc' => 'Description',
                'project' => [
                    'source' => 'zebra',
                    'id' => '100',
                ],
                'alias' => 'test',
            ],
            'isIndividual' => false,
            'role' => ['id' => 1, 'name' => 'Developer'],
            'desc' => 'Description',
            'updatedAt' => time(),
        ];

        $newFormatFrame = [
            'uuid' => 'uuid1',
            'start' => time() - 3600,
            'stop' => time(),
            'activity' => [
                'key' => [
                    'source' => 'zebra',
                    'id' => '123',
                ],
            ],
            'isIndividual' => false,
            'role' => ['id' => 1, 'name' => 'Developer'],
            'desc' => 'Description',
            'updatedAt' => time(),
        ];

        $framesData = ['uuid1' => $oldFormatFrame];
        $this->framesStorage->method('read')->willReturn($framesData);
        $this->currentFrameStorage->method('read')->willReturn([]);

        $this->framesStorage
            ->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($data) use ($newFormatFrame) {
                return isset($data['uuid1'])
                    && !isset($data['uuid1']['activity']['name'])
                    && !isset($data['uuid1']['activity']['desc'])
                    && !isset($data['uuid1']['activity']['project'])
                    && !isset($data['uuid1']['activity']['alias'])
                    && isset($data['uuid1']['activity']['key'])
                    && isset($data['uuid1']['roleId'])
                    && $data['uuid1']['roleId'] === 1
                    && !isset($data['uuid1']['role']); // Should not have 'role' key
            }));

        $this->currentFrameStorage->expects($this->once())->method('write')->with([]);

        $migratedCount = $this->migrationService->migrateFrames();
        $this->assertEquals(1, $migratedCount);
    }

    public function testMigrateFramesSkipsNewFormatFrames(): void
    {
        $newFormatFrame = [
            'uuid' => 'uuid1',
            'start' => time() - 3600,
            'stop' => time(),
            'activity' => [
                'key' => [
                    'source' => 'zebra',
                    'id' => '123',
                ],
            ],
            'isIndividual' => false,
            'roleId' => 1, // New format: only roleId
            'desc' => 'Description',
            'updatedAt' => time(),
        ];

        $framesData = ['uuid1' => $newFormatFrame];
        $this->framesStorage->method('read')->willReturn($framesData);
        $this->currentFrameStorage->method('read')->willReturn([]);

        $this->framesStorage
            ->expects($this->once())
            ->method('write')
            ->with($framesData); // Should write same data (no changes)

        $migratedCount = $this->migrationService->migrateFrames();
        $this->assertEquals(0, $migratedCount);
    }

    public function testMigrateFramesHandlesCurrentFrame(): void
    {
        $oldFormatCurrentFrame = [
            'uuid' => 'uuid2',
            'start' => time() - 1800,
            'stop' => null,
            'activity' => [
                'key' => [
                    'source' => 'zebra',
                    'id' => '456',
                ],
                'name' => 'Current Activity',
                'desc' => 'Description',
                'project' => [
                    'source' => 'zebra',
                    'id' => '200',
                ],
            ],
            'isIndividual' => false,
            'role' => ['id' => 1, 'name' => 'Developer'],
            'desc' => 'Description',
            'updatedAt' => time(),
        ];

        $this->framesStorage->method('read')->willReturn([]);
        $this->currentFrameStorage->method('read')->willReturn($oldFormatCurrentFrame);

        $this->currentFrameStorage
            ->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($data) {
                return !isset($data['activity']['name'])
                    && !isset($data['activity']['desc'])
                    && !isset($data['activity']['project'])
                    && isset($data['activity']['key'])
                    && isset($data['roleId'])
                    && !isset($data['role']); // Should not have 'role' key
            }));

        $migratedCount = $this->migrationService->migrateFrames();
        $this->assertEquals(1, $migratedCount);
    }

    public function testMigrateFrameConvertsOldFormat(): void
    {
        $oldFormat = [
            'uuid' => 'uuid1',
            'start' => time() - 3600,
            'stop' => time(),
            'activity' => [
                'key' => [
                    'source' => 'zebra',
                    'id' => '123',
                ],
                'name' => 'Test Activity',
                'desc' => 'Description',
                'project' => [
                    'source' => 'zebra',
                    'id' => '100',
                ],
                'alias' => 'test',
            ],
            'isIndividual' => false,
            'role' => ['id' => 1, 'name' => 'Developer'], // Old format
            'desc' => 'Description',
            'updatedAt' => time(),
        ];

        $migrated = $this->migrationService->migrateFrame($oldFormat);

        $this->assertArrayHasKey('activity', $migrated);
        $this->assertArrayHasKey('key', $migrated['activity']);
        $this->assertArrayNotHasKey('name', $migrated['activity']);
        $this->assertArrayNotHasKey('desc', $migrated['activity']);
        $this->assertArrayNotHasKey('project', $migrated['activity']);
        $this->assertArrayNotHasKey('alias', $migrated['activity']);
        // Check role normalization
        $this->assertArrayHasKey('roleId', $migrated);
        $this->assertEquals(1, $migrated['roleId']);
        $this->assertArrayNotHasKey('role', $migrated); // Should not have 'role' key
    }

    public function testMigrateFrameLeavesNewFormatUnchanged(): void
    {
        $newFormat = [
            'uuid' => 'uuid1',
            'start' => time() - 3600,
            'stop' => time(),
            'activity' => [
                'key' => [
                    'source' => 'zebra',
                    'id' => '123',
                ],
            ],
            'isIndividual' => false,
            'roleId' => 1, // New format: only roleId
            'desc' => 'Description',
            'updatedAt' => time(),
        ];

        $migrated = $this->migrationService->migrateFrame($newFormat);

        $this->assertEquals($newFormat, $migrated);
    }
}

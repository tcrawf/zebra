<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Client\ZebraApiException;
use Tcrawf\Zebra\Command\RefreshCommand;
use Tcrawf\Zebra\Config\ConfigFileStorageInterface;
use Tcrawf\Zebra\Project\ProjectApiServiceInterface;
use Tcrawf\Zebra\Project\ZebraProjectRepositoryInterface;
use Tcrawf\Zebra\User\UserApiServiceInterface;
use Tcrawf\Zebra\User\UserRepositoryInterface;

class RefreshCommandTest extends TestCase
{
    private UserRepositoryInterface&MockObject $userRepository;
    private ZebraProjectRepositoryInterface&MockObject $projectRepository;
    private UserApiServiceInterface&MockObject $userApiService;
    private ProjectApiServiceInterface&MockObject $projectApiService;
    private ConfigFileStorageInterface&MockObject $configStorage;
    private RefreshCommand $command;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->projectRepository = $this->createMock(ZebraProjectRepositoryInterface::class);
        $this->userApiService = $this->createMock(UserApiServiceInterface::class);
        $this->projectApiService = $this->createMock(ProjectApiServiceInterface::class);
        $this->configStorage = $this->createMock(ConfigFileStorageInterface::class);

        $this->command = new RefreshCommand(
            $this->userRepository,
            $this->projectRepository,
            $this->userApiService,
            $this->projectApiService,
            $this->configStorage
        );

        $application = new Application();
        $application->add($this->command);
        $this->commandTester = new CommandTester($this->command);
    }

    public function testSuccessfulRefresh(): void
    {
        $userId = 100;
        $userData = [
            'user' => [
                'id' => $userId,
                'username' => 'testuser',
                'email' => 'test@example.com',
            ],
            'roles' => [],
        ];
        $projectsData = [
            1 => ['id' => 1, 'name' => 'Project 1', 'status' => 1, 'activities' => []],
            2 => ['id' => 2, 'name' => 'Project 2', 'status' => 1, 'activities' => []],
        ];

        $this->configStorage
            ->expects($this->once())
            ->method('get')
            ->with('user.id')
            ->willReturn($userId);

        $this->userApiService
            ->expects($this->once())
            ->method('fetchById')
            ->with($userId)
            ->willReturn($userData);

        $this->projectApiService
            ->expects($this->once())
            ->method('fetchAll')
            ->willReturn($projectsData);

        $this->userRepository
            ->expects($this->once())
            ->method('refreshFromData')
            ->with($userId, $userData);

        $this->projectRepository
            ->expects($this->once())
            ->method('refreshFromData')
            ->with($projectsData);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Fetching user data...', $output);
        $this->assertStringContainsString('Fetching projects data...', $output);
        $this->assertStringContainsString('Writing data to cache...', $output);
        $this->assertStringContainsString('Successfully refreshed user data and 2 projects', $output);
    }

    public function testRefreshFailsWhenNoUserConfigured(): void
    {
        $this->configStorage
            ->expects($this->once())
            ->method('get')
            ->with('user.id')
            ->willReturn(null);

        $this->userApiService
            ->expects($this->never())
            ->method('fetchById');

        $this->projectApiService
            ->expects($this->never())
            ->method('fetchAll');

        $this->userRepository
            ->expects($this->never())
            ->method('refreshFromData');

        $this->projectRepository
            ->expects($this->never())
            ->method('refreshFromData');

        $this->commandTester->execute([]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        // Normalize whitespace for comparison
        $normalizedOutput = preg_replace('/\s+/', ' ', $output);
        $this->assertStringContainsString(
            'No user configured. Use "zebra user --init" to set up a user',
            $normalizedOutput
        );
    }

    public function testRefreshFailsWhenUserFetchFails(): void
    {
        $userId = 100;

        $this->configStorage
            ->expects($this->once())
            ->method('get')
            ->with('user.id')
            ->willReturn($userId);

        $this->userApiService
            ->expects($this->once())
            ->method('fetchById')
            ->with($userId)
            ->willThrowException(new ZebraApiException('Failed to fetch user from Zebra API: Connection timeout'));

        $this->projectApiService
            ->expects($this->never())
            ->method('fetchAll');

        $this->userRepository
            ->expects($this->never())
            ->method('refreshFromData');

        $this->projectRepository
            ->expects($this->never())
            ->method('refreshFromData');

        $this->commandTester->execute([]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        // Normalize whitespace for comparison
        $normalizedOutput = preg_replace('/\s+/', ' ', $output);
        $this->assertStringContainsString('Fetching user data', $normalizedOutput);
        $this->assertStringContainsString('Failed to fetch user data', $normalizedOutput);
        $this->assertStringContainsString('Connection timeout', $normalizedOutput);
    }

    public function testRefreshFailsWhenProjectsFetchFails(): void
    {
        $userId = 100;
        $userData = [
            'user' => [
                'id' => $userId,
                'username' => 'testuser',
                'email' => 'test@example.com',
            ],
            'roles' => [],
        ];

        $this->configStorage
            ->expects($this->once())
            ->method('get')
            ->with('user.id')
            ->willReturn($userId);

        $this->userApiService
            ->expects($this->once())
            ->method('fetchById')
            ->with($userId)
            ->willReturn($userData);

        $this->projectApiService
            ->expects($this->once())
            ->method('fetchAll')
            ->willThrowException(new ZebraApiException('Failed to fetch projects from Zebra API: Server error'));

        $this->userRepository
            ->expects($this->never())
            ->method('refreshFromData');

        $this->projectRepository
            ->expects($this->never())
            ->method('refreshFromData');

        $this->commandTester->execute([]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Fetching user data...', $output);
        $this->assertStringContainsString('Fetching projects data...', $output);
        $this->assertStringContainsString('Failed to fetch projects data', $output);
        $this->assertStringContainsString('Server error', $output);
    }

    public function testRefreshDoesNotWriteDataWhenUserFetchFails(): void
    {
        $userId = 100;

        $this->configStorage
            ->expects($this->once())
            ->method('get')
            ->with('user.id')
            ->willReturn($userId);

        $this->userApiService
            ->expects($this->once())
            ->method('fetchById')
            ->with($userId)
            ->willThrowException(new ZebraApiException('Failed to fetch user from Zebra API'));

        $this->userRepository
            ->expects($this->never())
            ->method('refreshFromData');

        $this->projectRepository
            ->expects($this->never())
            ->method('refreshFromData');

        $this->commandTester->execute([]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function testRefreshDoesNotWriteDataWhenProjectsFetchFails(): void
    {
        $userId = 100;
        $userData = [
            'user' => [
                'id' => $userId,
                'username' => 'testuser',
                'email' => 'test@example.com',
            ],
            'roles' => [],
        ];

        $this->configStorage
            ->expects($this->once())
            ->method('get')
            ->with('user.id')
            ->willReturn($userId);

        $this->userApiService
            ->expects($this->once())
            ->method('fetchById')
            ->with($userId)
            ->willReturn($userData);

        $this->projectApiService
            ->expects($this->once())
            ->method('fetchAll')
            ->willThrowException(new ZebraApiException('Failed to fetch projects from Zebra API'));

        $this->userRepository
            ->expects($this->never())
            ->method('refreshFromData');

        $this->projectRepository
            ->expects($this->never())
            ->method('refreshFromData');

        $this->commandTester->execute([]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function testRefreshHandlesGenericApiException(): void
    {
        $userId = 100;

        $this->configStorage
            ->expects($this->once())
            ->method('get')
            ->with('user.id')
            ->willReturn($userId);

        $this->userApiService
            ->expects($this->once())
            ->method('fetchById')
            ->with($userId)
            ->willThrowException(new ZebraApiException('Generic API error'));

        $this->commandTester->execute([]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Failed to refresh data: Generic API error', $output);
    }

    public function testRefreshHandlesUnexpectedException(): void
    {
        $userId = 100;

        $this->configStorage
            ->expects($this->once())
            ->method('get')
            ->with('user.id')
            ->willReturn($userId);

        $this->userApiService
            ->expects($this->once())
            ->method('fetchById')
            ->with($userId)
            ->willThrowException(new \RuntimeException('Unexpected error'));

        $this->commandTester->execute([]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('An unexpected error occurred: Unexpected error', $output);
    }

    public function testRefreshWithEmptyProjectsList(): void
    {
        $userId = 100;
        $userData = [
            'user' => [
                'id' => $userId,
                'username' => 'testuser',
                'email' => 'test@example.com',
            ],
            'roles' => [],
        ];
        $projectsData = [];

        $this->configStorage
            ->expects($this->once())
            ->method('get')
            ->with('user.id')
            ->willReturn($userId);

        $this->userApiService
            ->expects($this->once())
            ->method('fetchById')
            ->with($userId)
            ->willReturn($userData);

        $this->projectApiService
            ->expects($this->once())
            ->method('fetchAll')
            ->willReturn($projectsData);

        $this->userRepository
            ->expects($this->once())
            ->method('refreshFromData')
            ->with($userId, $userData);

        $this->projectRepository
            ->expects($this->once())
            ->method('refreshFromData')
            ->with($projectsData);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Successfully refreshed user data and 0 projects', $output);
    }

    public function testRefreshWithStringUserIdInConfig(): void
    {
        $userId = 100;
        $userData = [
            'user' => [
                'id' => $userId,
                'username' => 'testuser',
                'email' => 'test@example.com',
            ],
            'roles' => [],
        ];
        $projectsData = [
            1 => ['id' => 1, 'name' => 'Project 1', 'status' => 1, 'activities' => []],
        ];

        $this->configStorage
            ->expects($this->once())
            ->method('get')
            ->with('user.id')
            ->willReturn('100'); // String instead of int

        $this->userApiService
            ->expects($this->once())
            ->method('fetchById')
            ->with($userId)
            ->willReturn($userData);

        $this->projectApiService
            ->expects($this->once())
            ->method('fetchAll')
            ->willReturn($projectsData);

        $this->userRepository
            ->expects($this->once())
            ->method('refreshFromData')
            ->with($userId, $userData);

        $this->projectRepository
            ->expects($this->once())
            ->method('refreshFromData')
            ->with($projectsData);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }
}

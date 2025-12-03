<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Command\UserCommand;
use Tcrawf\Zebra\Config\ConfigFileStorageInterface;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\User\User;
use Tcrawf\Zebra\User\UserRepositoryInterface;

class UserCommandTest extends TestCase
{
    private UserRepositoryInterface&MockObject $userRepository;
    private ConfigFileStorageInterface&MockObject $configStorage;
    private UserCommand $command;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->configStorage = $this->createMock(ConfigFileStorageInterface::class);

        $this->command = new UserCommand($this->userRepository, $this->configStorage);

        $application = new Application();
        $application->add($this->command);
        $this->commandTester = new CommandTester($this->command);
    }

    public function testDisplayCurrentUserWhenNoUserConfigured(): void
    {
        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUser')
            ->willReturn(null);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        // Normalize whitespace for comparison
        $normalizedOutput = preg_replace('/\s+/', ' ', $output);
        $this->assertStringContainsString(
            'No user configured. Use "zebra user --init" to set up a user',
            $normalizedOutput
        );
    }

    public function testDisplayCurrentUserWithUserAndDefaultRole(): void
    {
        $role = new Role(1, null, 'Developer', 'Developer', 'employee', 'active');
        $user = new User(
            id: 100,
            username: 'testuser',
            firstname: 'Test',
            lastname: 'User',
            name: 'Test User',
            email: 'test@example.com',
            roles: [$role]
        );

        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUser')
            ->willReturn($user);

        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUserDefaultRole')
            ->willReturn($role);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('ID: 100', $output);
        $this->assertStringContainsString('Email: test@example.com', $output);
        $this->assertStringContainsString('Default Role: Developer', $output);
    }

    public function testDisplayCurrentUserWithUserButNoDefaultRole(): void
    {
        $role = new Role(1, null, 'Developer', 'Developer', 'employee', 'active');
        $user = new User(
            id: 100,
            username: 'testuser',
            firstname: 'Test',
            lastname: 'User',
            name: 'Test User',
            email: 'test@example.com',
            roles: [$role]
        );

        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUser')
            ->willReturn($user);

        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUserDefaultRole')
            ->willReturn(null);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('ID: 100', $output);
        $this->assertStringContainsString('Email: test@example.com', $output);
        $this->assertStringContainsString('Default Role: Not set', $output);
    }

    public function testInitializeUserSuccessfully(): void
    {
        $role1 = new Role(1, null, 'Developer', 'Developer', 'employee', 'active');
        $role2 = new Role(2, null, 'Manager', 'Manager', 'employee', 'active');
        $user = new User(
            id: 100,
            username: 'testuser',
            firstname: 'Test',
            lastname: 'User',
            name: 'Test User',
            email: 'test@example.com',
            roles: [$role1, $role2]
        );

        $this->userRepository
            ->expects($this->once())
            ->method('getByEmail')
            ->with('test@example.com')
            ->willReturn($user);

        $this->configStorage
            ->expects($this->exactly(2))
            ->method('set')
            ->willReturnCallback(function (string $key, mixed $value) {
                if ($key === 'user.id' && $value === 100) {
                    return;
                }
                if ($key === 'user.defaultRole.id' && $value === 2) {
                    return;
                }
                $this->fail("Unexpected call to set with key '{$key}' and value " . var_export($value, true));
            });

        $this->commandTester->setInputs(['test@example.com', 'Manager']);
        $this->commandTester->execute(['--init' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('User found: Test User (ID: 100)', $output);
        $this->assertStringContainsString('Default role set to: Manager', $output);
    }

    public function testInitializeUserWithEmptyEmail(): void
    {
        $this->userRepository
            ->expects($this->never())
            ->method('getByEmail');

        $this->configStorage
            ->expects($this->never())
            ->method('set');

        $this->commandTester->setInputs(['']);
        $this->commandTester->execute(['--init' => true]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Email is required.', $this->commandTester->getDisplay());
    }

    public function testInitializeUserNotFound(): void
    {
        $this->userRepository
            ->expects($this->once())
            ->method('getByEmail')
            ->with('notfound@example.com')
            ->willReturn(null);

        $this->configStorage
            ->expects($this->never())
            ->method('set');

        $this->commandTester->setInputs(['notfound@example.com']);
        $this->commandTester->execute(['--init' => true]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString(
            "User with email 'notfound@example.com' not found.",
            $this->commandTester->getDisplay()
        );
    }

    public function testInitializeUserWithNoRoles(): void
    {
        $user = new User(
            id: 100,
            username: 'testuser',
            firstname: 'Test',
            lastname: 'User',
            name: 'Test User',
            email: 'test@example.com',
            roles: []
        );

        $this->userRepository
            ->expects($this->once())
            ->method('getByEmail')
            ->with('test@example.com')
            ->willReturn($user);

        $this->configStorage
            ->expects($this->once())
            ->method('set')
            ->with('user.id', 100);

        $this->commandTester->setInputs(['test@example.com']);
        $this->commandTester->execute(['--init' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('No roles available for this user.', $this->commandTester->getDisplay());
    }

    public function testInitializeUserWithSingleRole(): void
    {
        $role = new Role(1, null, 'Developer', 'Developer', 'employee', 'active');
        $user = new User(
            id: 100,
            username: 'testuser',
            firstname: 'Test',
            lastname: 'User',
            name: 'Test User',
            email: 'test@example.com',
            roles: [$role]
        );

        $this->userRepository
            ->expects($this->once())
            ->method('getByEmail')
            ->with('test@example.com')
            ->willReturn($user);

        $this->configStorage
            ->expects($this->exactly(2))
            ->method('set')
            ->willReturnCallback(function (string $key, mixed $value) {
                if ($key === 'user.id' && $value === 100) {
                    return;
                }
                if ($key === 'user.defaultRole.id' && $value === 1) {
                    return;
                }
                $this->fail("Unexpected call to set with key '{$key}' and value " . var_export($value, true));
            });

        $this->commandTester->setInputs(['test@example.com', 'Developer']);
        $this->commandTester->execute(['--init' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('User found: Test User (ID: 100)', $output);
        $this->assertStringContainsString('Default role set to: Developer', $output);
    }

    public function testInitializeUserNonInteractiveWithEmailAndRole(): void
    {
        $role = new Role(1, null, 'Developer', 'Developer', 'employee', 'active');
        $user = new User(
            id: 100,
            username: 'testuser',
            firstname: 'Test',
            lastname: 'User',
            name: 'Test User',
            email: 'test@example.com',
            roles: [$role]
        );

        $this->userRepository
            ->expects($this->once())
            ->method('getByEmail')
            ->with('test@example.com')
            ->willReturn($user);

        $this->configStorage
            ->expects($this->exactly(2))
            ->method('set')
            ->willReturnCallback(function (string $key, mixed $value) {
                if ($key === 'user.id' && $value === 100) {
                    return;
                }
                if ($key === 'user.defaultRole.id' && $value === 1) {
                    return;
                }
                $this->fail("Unexpected call to set with key '{$key}' and value " . var_export($value, true));
            });

        $this->commandTester->execute([
            '--init' => true,
            '--email' => 'test@example.com',
            '--role' => 'Developer'
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('User found: Test User (ID: 100)', $output);
        $this->assertStringContainsString('Default role set to: Developer', $output);
    }

    public function testInitializeUserNonInteractiveWithEmailAndRoleId(): void
    {
        $role = new Role(1, null, 'Developer', 'Developer', 'employee', 'active');
        $user = new User(
            id: 100,
            username: 'testuser',
            firstname: 'Test',
            lastname: 'User',
            name: 'Test User',
            email: 'test@example.com',
            roles: [$role]
        );

        $this->userRepository
            ->expects($this->once())
            ->method('getByEmail')
            ->with('test@example.com')
            ->willReturn($user);

        $this->configStorage
            ->expects($this->exactly(2))
            ->method('set')
            ->willReturnCallback(function (string $key, mixed $value) {
                if ($key === 'user.id' && $value === 100) {
                    return;
                }
                if ($key === 'user.defaultRole.id' && $value === 1) {
                    return;
                }
                $this->fail("Unexpected call to set with key '{$key}' and value " . var_export($value, true));
            });

        $this->commandTester->execute([
            '--init' => true,
            '--email' => 'test@example.com',
            '--role' => '1'
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('User found: Test User (ID: 100)', $output);
        $this->assertStringContainsString('Default role set to: Developer', $output);
    }

    public function testInitializeUserNonInteractiveWithoutEmail(): void
    {
        $this->userRepository
            ->expects($this->never())
            ->method('getByEmail');

        $this->configStorage
            ->expects($this->never())
            ->method('set');

        $this->commandTester->execute(['--init' => true], ['interactive' => false]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        // Normalize whitespace for comparison
        $normalizedOutput = preg_replace('/\s+/', ' ', $output);
        $this->assertStringContainsString(
            'Email is required. Use --email option for non-interactive mode',
            $normalizedOutput
        );
    }

    public function testInitializeUserNonInteractiveWithoutRole(): void
    {
        $role = new Role(1, null, 'Developer', 'Developer', 'employee', 'active');
        $user = new User(
            id: 100,
            username: 'testuser',
            firstname: 'Test',
            lastname: 'User',
            name: 'Test User',
            email: 'test@example.com',
            roles: [$role]
        );

        $this->userRepository
            ->expects($this->once())
            ->method('getByEmail')
            ->with('test@example.com')
            ->willReturn($user);

        $this->configStorage
            ->expects($this->once())
            ->method('set')
            ->with('user.id', 100);

        $this->commandTester->execute([
            '--init' => true,
            '--email' => 'test@example.com'
        ], ['interactive' => false]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        // Normalize whitespace for comparison
        $normalizedOutput = preg_replace('/\s+/', ' ', $output);
        $this->assertStringContainsString(
            'Role is required. Use --role option for non-interactive mode',
            $normalizedOutput
        );
    }

    public function testInitializeUserWithInvalidRoleIdentifier(): void
    {
        $role = new Role(1, null, 'Developer', 'Developer', 'employee', 'active');
        $user = new User(
            id: 100,
            username: 'testuser',
            firstname: 'Test',
            lastname: 'User',
            name: 'Test User',
            email: 'test@example.com',
            roles: [$role]
        );

        $this->userRepository
            ->expects($this->once())
            ->method('getByEmail')
            ->with('test@example.com')
            ->willReturn($user);

        $this->configStorage
            ->expects($this->once())
            ->method('set')
            ->with('user.id', 100);

        $this->commandTester->execute([
            '--init' => true,
            '--email' => 'test@example.com',
            '--role' => 'InvalidRole'
        ]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString(
            "Role 'InvalidRole' not found for this user.",
            $this->commandTester->getDisplay()
        );
    }
}

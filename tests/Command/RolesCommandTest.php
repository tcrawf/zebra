<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Command\RolesCommand;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\User\User;
use Tcrawf\Zebra\User\UserRepositoryInterface;

class RolesCommandTest extends TestCase
{
    private UserRepositoryInterface&MockObject $userRepository;
    private RolesCommand $command;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);

        $this->command = new RolesCommand($this->userRepository);

        $application = new Application();
        $application->add($this->command);
        $this->commandTester = new CommandTester($this->command);
    }

    public function testDisplayRolesForUserWithMultipleRoles(): void
    {
        $role1 = new Role(1, null, 'Developer', 'Senior Developer', 'employee', 'active');
        $role2 = new Role(2, 1, 'Manager', 'Project Manager', 'employee', 'active');
        $role3 = new Role(3, null, 'Admin', 'System Administrator', 'admin', 'active');
        $user = new User(
            id: 100,
            username: 'testuser',
            firstname: 'Test',
            lastname: 'User',
            name: 'Test User',
            email: 'test@example.com',
            roles: [$role1, $role2, $role3]
        );

        $this->userRepository
            ->expects($this->never())
            ->method('getCurrentUser');

        $this->userRepository
            ->expects($this->once())
            ->method('getById')
            ->with(100)
            ->willReturn($user);

        $this->commandTester->execute(['user-id' => '100']);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Roles for user: Test User (ID: 100)', $output);
        $this->assertStringContainsString('ID', $output);
        $this->assertStringContainsString('Name', $output);
        $this->assertStringContainsString('Full Name', $output);
        $this->assertStringContainsString('Type', $output);
        $this->assertStringContainsString('Status', $output);
        $this->assertStringContainsString('Parent ID', $output);
        $this->assertStringContainsString('1', $output);
        $this->assertStringContainsString('Developer', $output);
        $this->assertStringContainsString('Senior Developer', $output);
        $this->assertStringContainsString('2', $output);
        $this->assertStringContainsString('Manager', $output);
        $this->assertStringContainsString('Project Manager', $output);
        $this->assertStringContainsString('3', $output);
        $this->assertStringContainsString('Admin', $output);
        $this->assertStringContainsString('System Administrator', $output);
    }

    public function testDisplayRolesForUserWithSingleRole(): void
    {
        $role = new Role(1, null, 'Developer', 'Developer', 'employee', 'active');
        $user = new User(
            id: 200,
            username: 'testuser2',
            firstname: 'Test',
            lastname: 'User2',
            name: 'Test User2',
            email: 'test2@example.com',
            roles: [$role]
        );

        $this->userRepository
            ->expects($this->never())
            ->method('getCurrentUser');

        $this->userRepository
            ->expects($this->once())
            ->method('getById')
            ->with(200)
            ->willReturn($user);

        $this->commandTester->execute(['user-id' => '200']);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Roles for user: Test User2 (ID: 200)', $output);
        $this->assertStringContainsString('Developer', $output);
    }

    public function testDisplayRolesForUserWithNoRoles(): void
    {
        $user = new User(
            id: 300,
            username: 'testuser3',
            firstname: 'Test',
            lastname: 'User3',
            name: 'Test User3',
            email: 'test3@example.com',
            roles: []
        );

        $this->userRepository
            ->expects($this->never())
            ->method('getCurrentUser');

        $this->userRepository
            ->expects($this->once())
            ->method('getById')
            ->with(300)
            ->willReturn($user);

        $this->commandTester->execute(['user-id' => '300']);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('User Test User3 (ID: 300) has no roles.', $output);
    }

    public function testDisplayRolesForUserWithRoleHavingParentId(): void
    {
        $role = new Role(2, 1, 'Manager', 'Project Manager', 'employee', 'active');
        $user = new User(
            id: 400,
            username: 'testuser4',
            firstname: 'Test',
            lastname: 'User4',
            name: 'Test User4',
            email: 'test4@example.com',
            roles: [$role]
        );

        $this->userRepository
            ->expects($this->never())
            ->method('getCurrentUser');

        $this->userRepository
            ->expects($this->once())
            ->method('getById')
            ->with(400)
            ->willReturn($user);

        $this->commandTester->execute(['user-id' => '400']);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Roles for user: Test User4 (ID: 400)', $output);
        $this->assertStringContainsString('1', $output); // Parent ID
    }

    public function testDisplayRolesForUserWithRoleHavingNullParentId(): void
    {
        $role = new Role(1, null, 'Developer', 'Developer', 'employee', 'active');
        $user = new User(
            id: 500,
            username: 'testuser5',
            firstname: 'Test',
            lastname: 'User5',
            name: 'Test User5',
            email: 'test5@example.com',
            roles: [$role]
        );

        $this->userRepository
            ->expects($this->never())
            ->method('getCurrentUser');

        $this->userRepository
            ->expects($this->once())
            ->method('getById')
            ->with(500)
            ->willReturn($user);

        $this->commandTester->execute(['user-id' => '500']);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Roles for user: Test User5 (ID: 500)', $output);
        // Parent ID should show as '-' when null
        $this->assertStringContainsString('-', $output);
    }

    public function testUserNotFound(): void
    {
        $this->userRepository
            ->expects($this->never())
            ->method('getCurrentUser');

        $this->userRepository
            ->expects($this->once())
            ->method('getById')
            ->with(999)
            ->willReturn(null);

        $this->commandTester->execute(['user-id' => '999']);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString(
            "User with ID '999' not found.",
            $this->commandTester->getDisplay()
        );
    }

    public function testInvalidUserIdNonNumeric(): void
    {
        $this->userRepository
            ->expects($this->never())
            ->method('getCurrentUser');

        $this->userRepository
            ->expects($this->never())
            ->method('getById');

        $this->commandTester->execute(['user-id' => 'abc']);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString(
            "Invalid user ID: 'abc'. User ID must be a number.",
            $this->commandTester->getDisplay()
        );
    }

    public function testDisplayRolesForCurrentUserWhenNoUserIdProvided(): void
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
            ->method('getCurrentUser')
            ->willReturn($user);

        $this->userRepository
            ->expects($this->never())
            ->method('getById');

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Roles for user: Test User (ID: 100)', $output);
        $this->assertStringContainsString('Developer', $output);
        $this->assertStringContainsString('Manager', $output);
    }

    public function testDisplayRolesForCurrentUserWhenEmptyUserIdProvided(): void
    {
        $role = new Role(1, null, 'Developer', 'Developer', 'employee', 'active');
        $user = new User(
            id: 200,
            username: 'testuser2',
            firstname: 'Test',
            lastname: 'User2',
            name: 'Test User2',
            email: 'test2@example.com',
            roles: [$role]
        );

        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUser')
            ->willReturn($user);

        $this->userRepository
            ->expects($this->never())
            ->method('getById');

        $this->commandTester->execute(['user-id' => '']);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Roles for user: Test User2 (ID: 200)', $output);
        $this->assertStringContainsString('Developer', $output);
    }

    public function testNoCurrentUserWhenNoUserIdProvided(): void
    {
        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUser')
            ->willReturn(null);

        $this->userRepository
            ->expects($this->never())
            ->method('getById');

        $this->commandTester->execute([]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString(
            'No user ID provided and no current user configured.',
            $output
        );
        // Normalize whitespace to handle line wrapping
        $normalizedOutput = preg_replace('/\s+/', ' ', $output);
        $this->assertStringContainsString('user --init', $normalizedOutput);
        $this->assertStringContainsString('provide a user ID', $normalizedOutput);
    }

    public function testNoCurrentUserWhenEmptyUserIdProvided(): void
    {
        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUser')
            ->willReturn(null);

        $this->userRepository
            ->expects($this->never())
            ->method('getById');

        $this->commandTester->execute(['user-id' => '']);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString(
            'No user ID provided and no current user configured.',
            $output
        );
    }

    public function testDisplayRolesForCurrentUserWithNoRoles(): void
    {
        $user = new User(
            id: 600,
            username: 'testuser6',
            firstname: 'Test',
            lastname: 'User6',
            name: 'Test User6',
            email: 'test6@example.com',
            roles: []
        );

        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUser')
            ->willReturn($user);

        $this->userRepository
            ->expects($this->never())
            ->method('getById');

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('User Test User6 (ID: 600) has no roles.', $output);
    }

    public function testInvalidUserIdNegativeNumber(): void
    {
        $this->userRepository
            ->expects($this->never())
            ->method('getCurrentUser');

        $this->userRepository
            ->expects($this->never())
            ->method('getById');

        $this->commandTester->execute(['user-id' => '-1']);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString(
            "Invalid user ID: '-1'. User ID must be a number.",
            $this->commandTester->getDisplay()
        );
    }

    public function testInvalidUserIdWithDecimal(): void
    {
        $this->userRepository
            ->expects($this->never())
            ->method('getCurrentUser');

        $this->userRepository
            ->expects($this->never())
            ->method('getById');

        $this->commandTester->execute(['user-id' => '100.5']);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString(
            "Invalid user ID: '100.5'. User ID must be a number.",
            $this->commandTester->getDisplay()
        );
    }
}

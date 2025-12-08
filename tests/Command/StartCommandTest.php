<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Activity\ActivityRepositoryInterface;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Command\Autocompletion\ActivityOrProjectAutocompletion;
use Tcrawf\Zebra\Command\StartCommand;
use Tcrawf\Zebra\Config\ConfigFileStorageInterface;
use Tcrawf\Zebra\Frame\Frame;
use Tcrawf\Zebra\Frame\FrameRepositoryInterface;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Tests\Helper\CommandTestTrait;
use Tcrawf\Zebra\Tests\Helper\TestEntityFactory;
use Tcrawf\Zebra\Track\TrackInterface;
use Tcrawf\Zebra\User\User;
use Tcrawf\Zebra\User\UserRepositoryInterface;
use Tcrawf\Zebra\Uuid\Uuid;

class StartCommandTest extends TestCase
{
    use CommandTestTrait;

    private TrackInterface&MockObject $track;
    private ActivityRepositoryInterface&MockObject $activityRepository;
    private UserRepositoryInterface&MockObject $userRepository;
    private FrameRepositoryInterface&MockObject $frameRepository;
    private ConfigFileStorageInterface&MockObject $configStorage;
    private ProjectRepositoryInterface&MockObject $projectRepository;
    private ActivityOrProjectAutocompletion&MockObject $autocompletion;
    private StartCommand $command;
    private CommandTester $commandTester;
    private Activity $activity;
    private Role $role;
    private User $user;

    protected function setUp(): void
    {
        $this->track = $this->createMock(TrackInterface::class);
        $this->activityRepository = $this->createMock(ActivityRepositoryInterface::class);
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->frameRepository = $this->createMock(FrameRepositoryInterface::class);
        $this->configStorage = $this->createMock(ConfigFileStorageInterface::class);
        $this->projectRepository = $this->createMock(ProjectRepositoryInterface::class);
        $this->autocompletion = $this->createMock(ActivityOrProjectAutocompletion::class);

        $this->command = new StartCommand(
            $this->track,
            $this->activityRepository,
            $this->userRepository,
            $this->frameRepository,
            $this->configStorage,
            $this->projectRepository,
            $this->autocompletion
        );

        $this->commandTester = $this->setupCommandTester($this->command);

        $this->activity = TestEntityFactory::createActivity(
            EntityKey::zebra(1),
            'Test Activity',
            'Description',
            EntityKey::zebra(100),
            'test-alias'
        );
        $this->role = TestEntityFactory::createRole(
            1,
            null,
            'Developer',
            'Developer',
            'employee',
            'active'
        );
        $this->user = TestEntityFactory::createUser(
            1,
            'testuser',
            'Test',
            'User',
            'Test User',
            'test@example.com',
            [$this->role]
        );
    }

    public function testStartWithActivityAlias(): void
    {
        $frame = new Frame(
            Uuid::random(),
            Carbon::now(),
            null,
            $this->activity,
            false,
            $this->role,
            'Test description'
        );

        $this->activityRepository
            ->expects($this->once())
            ->method('getByAlias')
            ->with('test-alias')
            ->willReturn($this->activity);

        $this->frameRepository
            ->expects($this->once())
            ->method('getLastUsedRoleForActivity')
            ->with($this->activity)
            ->willReturn(null);

        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUserDefaultRole')
            ->willReturn($this->role);

        $this->track
            ->expects($this->once())
            ->method('start')
            ->with($this->activity, null, null, true, false, $this->role)
            ->willReturn($frame);

        $this->commandTester->execute(['activity' => 'test-alias']);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Frame started successfully', $this->commandTester->getDisplay());
    }

    public function testStartWithActivityId(): void
    {
        $frame = new Frame(
            Uuid::random(),
            Carbon::now(),
            null,
            $this->activity,
            false,
            $this->role,
            'Test description'
        );

        $this->activityRepository
            ->expects($this->once())
            ->method('getByAlias')
            ->with('1')
            ->willReturn(null);

        $this->activityRepository
            ->expects($this->once())
            ->method('get')
            ->with(EntityKey::zebra(1))
            ->willReturn($this->activity);

        $this->frameRepository
            ->expects($this->once())
            ->method('getLastUsedRoleForActivity')
            ->with($this->activity)
            ->willReturn(null);

        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUserDefaultRole')
            ->willReturn($this->role);

        $this->track
            ->expects($this->once())
            ->method('start')
            ->with($this->activity, null, null, true, false, $this->role)
            ->willReturn($frame);

        $this->commandTester->execute(['activity' => '1']);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testStartWithLastUsedRoleForActivity(): void
    {
        $lastUsedRole = new Role(2, null, 'Manager', 'Manager', 'employee', 'active');
        $frame = new Frame(
            Uuid::random(),
            Carbon::now(),
            null,
            $this->activity,
            false,
            $lastUsedRole,
            'Test description'
        );

        $this->activityRepository
            ->expects($this->once())
            ->method('getByAlias')
            ->with('test-alias')
            ->willReturn($this->activity);

        $this->frameRepository
            ->expects($this->once())
            ->method('getLastUsedRoleForActivity')
            ->with($this->activity)
            ->willReturn($lastUsedRole);

        $this->track
            ->expects($this->once())
            ->method('start')
            ->with($this->activity, null, null, true, false, $lastUsedRole)
            ->willReturn($frame);

        $this->commandTester->execute(['activity' => 'test-alias']);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString(
            "Using last role for activity 'Test Activity': Manager",
            $this->commandTester->getDisplay()
        );
    }

    public function testStartWithRoleOptionById(): void
    {
        $selectedRole = new Role(2, null, 'Manager', 'Manager', 'employee', 'active');
        $frame = new Frame(
            Uuid::random(),
            Carbon::now(),
            null,
            $this->activity,
            false,
            $selectedRole,
            'Test description'
        );

        $this->activityRepository
            ->expects($this->once())
            ->method('getByAlias')
            ->with('test-alias')
            ->willReturn($this->activity);

        $userWithMultipleRoles = new User(
            id: 1,
            username: 'testuser',
            firstname: 'Test',
            lastname: 'User',
            name: 'Test User',
            email: 'test@example.com',
            roles: [$this->role, $selectedRole]
        );

        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUser')
            ->willReturn($userWithMultipleRoles);

        $this->track
            ->expects($this->once())
            ->method('start')
            ->with($this->activity, null, null, true, false, $selectedRole)
            ->willReturn($frame);

        $this->commandTester->execute([
            'activity' => 'test-alias',
            '--role' => '2'
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testStartWithRoleOptionByName(): void
    {
        $selectedRole = new Role(2, null, 'Manager', 'Manager', 'employee', 'active');
        $frame = new Frame(
            Uuid::random(),
            Carbon::now(),
            null,
            $this->activity,
            false,
            $selectedRole,
            'Test description'
        );

        $this->activityRepository
            ->expects($this->once())
            ->method('getByAlias')
            ->with('test-alias')
            ->willReturn($this->activity);

        $userWithMultipleRoles = new User(
            id: 1,
            username: 'testuser',
            firstname: 'Test',
            lastname: 'User',
            name: 'Test User',
            email: 'test@example.com',
            roles: [$this->role, $selectedRole]
        );

        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUser')
            ->willReturn($userWithMultipleRoles);

        $this->track
            ->expects($this->once())
            ->method('start')
            ->with($this->activity, null, null, true, false, $selectedRole)
            ->willReturn($frame);

        $this->commandTester->execute([
            'activity' => 'test-alias',
            '--role' => 'Manager'
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testStartWithDescription(): void
    {
        $frame = new Frame(
            Uuid::random(),
            Carbon::now(),
            null,
            $this->activity,
            false,
            $this->role,
            'Custom description'
        );

        $this->activityRepository
            ->expects($this->once())
            ->method('getByAlias')
            ->with('test-alias')
            ->willReturn($this->activity);

        $this->frameRepository
            ->expects($this->once())
            ->method('getLastUsedRoleForActivity')
            ->with($this->activity)
            ->willReturn(null);

        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUserDefaultRole')
            ->willReturn($this->role);

        $this->track
            ->expects($this->once())
            ->method('start')
            ->with($this->activity, 'Custom description', null, true, false, $this->role)
            ->willReturn($frame);

        $this->commandTester->execute([
            'activity' => 'test-alias',
            '--description' => 'Custom description'
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testStartWithPlusDescriptionSingleWord(): void
    {
        $frame = new Frame(
            Uuid::random(),
            Carbon::now(),
            null,
            $this->activity,
            false,
            $this->role,
            'description'
        );

        $this->activityRepository
            ->expects($this->once())
            ->method('getByAlias')
            ->with('test-alias')
            ->willReturn($this->activity);

        $this->frameRepository
            ->expects($this->once())
            ->method('getLastUsedRoleForActivity')
            ->with($this->activity)
            ->willReturn(null);

        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUserDefaultRole')
            ->willReturn($this->role);

        $this->track
            ->expects($this->once())
            ->method('start')
            ->with($this->activity, 'description', null, true, false, $this->role)
            ->willReturn($frame);

        $this->commandTester->execute([
            'activity' => ['test-alias', '+description']
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testStartWithPlusDescriptionMultiWord(): void
    {
        $frame = new Frame(
            Uuid::random(),
            Carbon::now(),
            null,
            $this->activity,
            false,
            $this->role,
            'Working on feature'
        );

        $this->activityRepository
            ->expects($this->once())
            ->method('getByAlias')
            ->with('test-alias')
            ->willReturn($this->activity);

        $this->frameRepository
            ->expects($this->once())
            ->method('getLastUsedRoleForActivity')
            ->with($this->activity)
            ->willReturn(null);

        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUserDefaultRole')
            ->willReturn($this->role);

        $this->track
            ->expects($this->once())
            ->method('start')
            ->with($this->activity, 'Working on feature', null, true, false, $this->role)
            ->willReturn($frame);

        $this->commandTester->execute([
            'activity' => ['test-alias', '+Working', 'on', 'feature']
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testStartWithPlusDescriptionTakesPrecedenceOverOption(): void
    {
        $frame = new Frame(
            Uuid::random(),
            Carbon::now(),
            null,
            $this->activity,
            false,
            $this->role,
            'Plus description'
        );

        $this->activityRepository
            ->expects($this->once())
            ->method('getByAlias')
            ->with('test-alias')
            ->willReturn($this->activity);

        $this->frameRepository
            ->expects($this->once())
            ->method('getLastUsedRoleForActivity')
            ->with($this->activity)
            ->willReturn(null);

        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUserDefaultRole')
            ->willReturn($this->role);

        // Plus description should take precedence over --description option
        $this->track
            ->expects($this->once())
            ->method('start')
            ->with($this->activity, 'Plus description', null, true, false, $this->role)
            ->willReturn($frame);

        $this->commandTester->execute([
            'activity' => ['test-alias', '+Plus', 'description'],
            '--description' => 'Option description'
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testStartWithNoGap(): void
    {
        $frame = new Frame(
            Uuid::random(),
            Carbon::now(),
            null,
            $this->activity,
            false,
            $this->role,
            ''
        );

        $this->activityRepository
            ->expects($this->once())
            ->method('getByAlias')
            ->with('test-alias')
            ->willReturn($this->activity);

        $this->frameRepository
            ->expects($this->once())
            ->method('getLastUsedRoleForActivity')
            ->with($this->activity)
            ->willReturn(null);

        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUserDefaultRole')
            ->willReturn($this->role);

        $this->track
            ->expects($this->once())
            ->method('start')
            ->with($this->activity, null, null, false, false, $this->role)
            ->willReturn($frame);

        $this->commandTester->execute([
            'activity' => 'test-alias',
            '--no-gap' => true
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testStartActivityNotFound(): void
    {
        $this->activityRepository
            ->expects($this->once())
            ->method('getByAlias')
            ->with('non-existent')
            ->willReturn(null);

        $this->activityRepository
            ->expects($this->once())
            ->method('searchByAlias')
            ->with('non-existent')
            ->willReturn([]);

        $this->projectRepository
            ->expects($this->once())
            ->method('getByNameLike')
            ->with('non-existent')
            ->willReturn([]);

        $this->commandTester->execute(['activity' => 'non-existent']);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString("Activity 'non-existent' not found", $this->commandTester->getDisplay());
    }

    public function testStartWithFrameAlreadyStartedException(): void
    {
        // Create an existing frame that's already started
        $existingFrame = new Frame(
            Uuid::random(),
            Carbon::now()->subHour(),
            null,
            $this->activity,
            false,
            $this->role,
            'Existing frame description'
        );

        // Mock track->getCurrent() to return the existing frame
        $this->track
            ->expects($this->once())
            ->method('getCurrent')
            ->willReturn($existingFrame);

        // Activity resolution should NOT be called since we return early
        $this->activityRepository
            ->expects($this->never())
            ->method('getByAlias');

        // track->start() should NOT be called since we return early
        $this->track
            ->expects($this->never())
            ->method('start');

        $this->commandTester->execute(['activity' => 'test-alias']);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('A frame is already started', $display);
        $this->assertStringContainsString('Stop or cancel the current frame', $display);
        $this->assertStringContainsString('Current frame:', $display);
        $this->assertStringContainsString('UUID=' . $existingFrame->uuid, $display);
        $this->assertStringContainsString('Activity=' . $existingFrame->activity->name, $display);
        $this->assertStringContainsString('Role=' . $existingFrame->role->name, $display);
    }

    public function testStartWithNoActivityButIssueKeysFoundUsesLastActivity(): void
    {
        $frame = new Frame(
            Uuid::random(),
            Carbon::now(),
            null,
            $this->activity,
            false,
            $this->role,
            'ABC-123 DEF-456'
        );

        // No activity provided, but issue keys in description
        // Should find last activity for these issue keys
        $this->frameRepository
            ->expects($this->once())
            ->method('getLastActivityForIssueKeys')
            ->with(['ABC-123', 'DEF-456'])
            ->willReturn($this->activity);

        $this->frameRepository
            ->expects($this->once())
            ->method('getLastUsedRoleForActivity')
            ->with($this->activity)
            ->willReturn(null);

        $this->userRepository
            ->expects($this->once())
            ->method('getCurrentUserDefaultRole')
            ->willReturn($this->role);

        $this->track
            ->expects($this->once())
            ->method('start')
            ->with($this->activity, 'ABC-123 DEF-456', null, true, false, $this->role)
            ->willReturn($frame);

        // Execute with only description containing issue keys, no activity
        $this->commandTester->execute([
            'activity' => ['+ABC-123', 'DEF-456']
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Frame started successfully', $this->commandTester->getDisplay());
    }

    public function testStartWithNoActivityButNoIssueKeysInDescription(): void
    {
        // No activity provided and no issue keys in description
        // getLastActivityForIssueKeys should NOT be called when there are no issue keys
        $this->frameRepository
            ->expects($this->never())
            ->method('getLastActivityForIssueKeys');

        // Since CommandTester doesn't support interactive prompts well,
        // we'll test that it fails when no search term is provided
        // In real usage, the user would provide a search term via the prompt
        $this->commandTester->execute([
            'activity' => ['+Some description without issue keys']
        ], ['interactive' => false]);

        // Should fail because no activity was found
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }
}

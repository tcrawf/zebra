<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Activity\ActivityRepositoryInterface;
use Tcrawf\Zebra\Command\AddCommand;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Command\Autocompletion\ActivityOrProjectAutocompletion;
use Tcrawf\Zebra\Config\ConfigFileStorageInterface;
use Tcrawf\Zebra\Exception\InvalidTimeException;
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

class AddCommandTest extends TestCase
{
    use CommandTestTrait;

    private TrackInterface&MockObject $track;
    private ActivityRepositoryInterface&MockObject $activityRepository;
    private UserRepositoryInterface&MockObject $userRepository;
    private FrameRepositoryInterface&MockObject $frameRepository;
    private ConfigFileStorageInterface&MockObject $configStorage;
    private ProjectRepositoryInterface&MockObject $projectRepository;
    private ActivityOrProjectAutocompletion&MockObject $autocompletion;
    private AddCommand $command;
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

        $this->command = new AddCommand(
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

    public function testAddWithActivityAlias(): void
    {
        $from = Carbon::now()->subHour();
        $to = Carbon::now();
        $frame = new Frame(
            Uuid::random(),
            $from,
            $to,
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
            ->method('add')
            ->with($this->activity, $this->anything(), $this->anything(), null, false, $this->role)
            ->willReturn($frame);

        $this->commandTester->execute([
            'activity' => 'test-alias',
            '--from' => $from->toIso8601String(),
            '--to' => $to->toIso8601String()
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Frame added successfully', $this->commandTester->getDisplay());
    }

    public function testAddWithActivityId(): void
    {
        $from = Carbon::now()->subHour();
        $to = Carbon::now();
        $frame = new Frame(
            Uuid::random(),
            $from,
            $to,
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
            ->method('add')
            ->with($this->activity, $this->anything(), $this->anything(), null, false, $this->role)
            ->willReturn($frame);

        $this->commandTester->execute([
            'activity' => '1',
            '--from' => $from->toIso8601String(),
            '--to' => $to->toIso8601String()
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testAddWithLastUsedRoleForActivity(): void
    {
        $from = Carbon::now()->subHour();
        $to = Carbon::now();
        $lastUsedRole = new Role(2, null, 'Manager', 'Manager', 'employee', 'active');
        $frame = new Frame(
            Uuid::random(),
            $from,
            $to,
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
            ->method('add')
            ->with($this->activity, $this->anything(), $this->anything(), null, false, $lastUsedRole)
            ->willReturn($frame);

        $this->commandTester->execute([
            'activity' => 'test-alias',
            '--from' => $from->toIso8601String(),
            '--to' => $to->toIso8601String()
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString(
            "Using last role for activity 'Test Activity': Manager",
            $this->commandTester->getDisplay()
        );
    }

    public function testAddWithRoleOptionById(): void
    {
        $from = Carbon::now()->subHour();
        $to = Carbon::now();
        $selectedRole = new Role(2, null, 'Manager', 'Manager', 'employee', 'active');
        $frame = new Frame(
            Uuid::random(),
            $from,
            $to,
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
            ->method('add')
            ->with($this->activity, $this->anything(), $this->anything(), null, false, $selectedRole)
            ->willReturn($frame);

        $this->commandTester->execute([
            'activity' => 'test-alias',
            '--from' => $from->toIso8601String(),
            '--to' => $to->toIso8601String(),
            '--role' => '2'
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testAddWithDescription(): void
    {
        $from = Carbon::now()->subHour();
        $to = Carbon::now();
        $frame = new Frame(
            Uuid::random(),
            $from,
            $to,
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
            ->method('add')
            ->with($this->activity, $this->anything(), $this->anything(), 'Custom description', false, $this->role)
            ->willReturn($frame);

        $this->commandTester->execute([
            'activity' => 'test-alias',
            '--from' => $from->toIso8601String(),
            '--to' => $to->toIso8601String(),
            '--description' => 'Custom description'
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testAddActivityNotFound(): void
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

        $from = Carbon::now()->subHour();
        $to = Carbon::now();

        $this->commandTester->execute([
            'activity' => 'non-existent',
            '--from' => $from->toIso8601String(),
            '--to' => $to->toIso8601String()
        ]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString("Activity 'non-existent' not found", $this->commandTester->getDisplay());
    }

    public function testAddMissingFromOption(): void
    {
        $this->activityRepository
            ->expects($this->once())
            ->method('getByAlias')
            ->with('test-alias')
            ->willReturn($this->activity);

        $this->commandTester->execute([
            'activity' => 'test-alias',
            '--to' => Carbon::now()->toIso8601String()
        ]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString(
            'Both --from and --to options are required',
            $this->commandTester->getDisplay()
        );
    }

    public function testAddMissingToOption(): void
    {
        $this->activityRepository
            ->expects($this->once())
            ->method('getByAlias')
            ->with('test-alias')
            ->willReturn($this->activity);

        $this->commandTester->execute([
            'activity' => 'test-alias',
            '--from' => Carbon::now()->subHour()->toIso8601String()
        ]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString(
            'Both --from and --to options are required',
            $this->commandTester->getDisplay()
        );
    }

    public function testAddWithInvalidTimeException(): void
    {
        $from = Carbon::now()->subHour();
        $to = Carbon::now();

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
            ->method('add')
            ->willThrowException(new InvalidTimeException('Invalid time range'));

        $this->commandTester->execute([
            'activity' => 'test-alias',
            '--from' => $from->toIso8601String(),
            '--to' => $to->toIso8601String()
        ]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Invalid time range', $this->commandTester->getDisplay());
    }

    public function testAddWithNoActivityButIssueKeysFoundUsesLastActivity(): void
    {
        $from = Carbon::now()->subHours(2);
        $to = Carbon::now()->subHour();
        $frame = new Frame(
            Uuid::random(),
            $from,
            $to,
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
            ->method('add')
            ->with(
                $this->activity,
                $this->callback(function ($fromParam) use ($from) {
                    return $fromParam instanceof \Carbon\Carbon
                        && $fromParam->timestamp === $from->timestamp;
                }),
                $this->callback(function ($toParam) use ($to) {
                    return $toParam instanceof \Carbon\Carbon
                        && $toParam->timestamp === $to->timestamp;
                }),
                'ABC-123 DEF-456',
                false,
                $this->role
            )
            ->willReturn($frame);

        // Execute with only description containing issue keys, no activity
        // Set interactive=false to avoid prompt issues in tests
        $this->commandTester->execute(
            [
                'activity' => ['+ABC-123', 'DEF-456'],
                '--from' => $from->toIso8601String(),
                '--to' => $to->toIso8601String()
            ],
            ['interactive' => false]
        );

        $display = $this->commandTester->getDisplay();
        $statusCode = $this->commandTester->getStatusCode();
        if ($statusCode !== 0) {
            $this->fail("Command failed with status code {$statusCode}. Output: {$display}");
        }
        $this->assertEquals(0, $statusCode);
        $this->assertStringContainsString('Frame added successfully', $display);
    }
}

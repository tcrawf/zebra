<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Client\ZebraApiException;
use Tcrawf\Zebra\Command\TimesheetDeleteCommand;
use Tcrawf\Zebra\Command\Autocompletion\TimesheetAutocompletion;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Timesheet\LocalTimesheetRepositoryInterface;
use Tcrawf\Zebra\Timesheet\TimesheetFactory;
use Tcrawf\Zebra\Timesheet\ZebraTimesheetRepositoryInterface;
use Exception;

class TimesheetDeleteCommandTest extends TestCase
{
    private LocalTimesheetRepositoryInterface&MockObject $localRepository;
    private ZebraTimesheetRepositoryInterface&MockObject $zebraRepository;
    private TimesheetAutocompletion&MockObject $autocompletion;
    private TimesheetDeleteCommand $command;
    private CommandTester $commandTester;
    private Activity $activity;
    private Role $role;

    protected function setUp(): void
    {
        $this->localRepository = $this->createMock(LocalTimesheetRepositoryInterface::class);
        $this->zebraRepository = $this->createMock(ZebraTimesheetRepositoryInterface::class);
        $this->autocompletion = $this->createMock(TimesheetAutocompletion::class);

        $this->command = new TimesheetDeleteCommand(
            $this->localRepository,
            $this->zebraRepository,
            $this->autocompletion
        );

        $application = new Application();
        $application->add($this->command);
        $this->commandTester = new CommandTester($this->command);

        $this->activity = new Activity(
            EntityKey::zebra(123),
            'Test Activity',
            'Activity Description',
            EntityKey::zebra(100),
            'activity-123'
        );
        $this->role = new Role(1, null, 'Developer', 'Developer', 'employee', 'active');
    }

    public function testDeleteTimesheetNotFound(): void
    {
        $this->localRepository
            ->expects($this->once())
            ->method('get')
            ->with('invalid-uuid')
            ->willReturn(null);

        $this->commandTester->execute(['timesheet' => 'invalid-uuid']);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('not found', $this->commandTester->getDisplay());
    }

    public function testDeleteLocalTimesheetOnly(): void
    {
        $date = Carbon::today();
        $timesheet = TimesheetFactory::create(
            $this->activity,
            'Test description',
            null,
            2.5,
            $date,
            $this->role,
            false,
            [],
            null // No zebraId
        );

        $this->localRepository
            ->expects($this->once())
            ->method('get')
            ->with($timesheet->uuid)
            ->willReturn($timesheet);

        $this->localRepository
            ->expects($this->once())
            ->method('remove')
            ->with($timesheet->uuid);

        $this->commandTester->setInputs(['yes']);
        $this->commandTester->execute(['timesheet' => $timesheet->uuid]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Timesheet deleted locally', $this->commandTester->getDisplay());
        $this->assertStringNotContainsString('Zebra', $this->commandTester->getDisplay());
    }

    public function testDeleteTimesheetWithZebraIdButNotRemote(): void
    {
        $date = Carbon::today();
        $timesheet = TimesheetFactory::create(
            $this->activity,
            'Test description',
            null,
            2.5,
            $date,
            $this->role,
            false,
            [],
            12345 // Has zebraId
        );

        $this->localRepository
            ->expects($this->once())
            ->method('get')
            ->with($timesheet->uuid)
            ->willReturn($timesheet);

        $this->localRepository
            ->expects($this->once())
            ->method('remove')
            ->with($timesheet->uuid);

        // Should not call zebraRepository->delete
        $this->zebraRepository
            ->expects($this->never())
            ->method('delete');

        $this->commandTester->setInputs(['yes', 'no']); // Confirm local, don't delete remote
        $this->commandTester->execute(['timesheet' => $timesheet->uuid]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Timesheet deleted locally', $this->commandTester->getDisplay());
        $this->assertStringContainsString('not deleted from Zebra', $this->commandTester->getDisplay());
    }

    public function testDeleteTimesheetWithZebraIdAndRemote(): void
    {
        $date = Carbon::today();
        $timesheet = TimesheetFactory::create(
            $this->activity,
            'Test description',
            null,
            2.5,
            $date,
            $this->role,
            false,
            [],
            12345 // Has zebraId
        );

        $this->localRepository
            ->expects($this->once())
            ->method('get')
            ->with($timesheet->uuid)
            ->willReturn($timesheet);

        $this->zebraRepository
            ->expects($this->once())
            ->method('delete')
            ->with(
                12345,
                $this->callback(function ($callback) {
                    // Verify callback returns true
                    return is_callable($callback) && $callback(12345) === true;
                })
            )
            ->willReturn(true);

        $this->localRepository
            ->expects($this->once())
            ->method('remove')
            ->with($timesheet->uuid);

        $this->commandTester->setInputs(['yes', 'yes']); // Confirm local and remote
        $this->commandTester->execute(['timesheet' => $timesheet->uuid]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Timesheet deleted from Zebra', $this->commandTester->getDisplay());
        $this->assertStringContainsString('Timesheet deleted locally', $this->commandTester->getDisplay());
    }

    public function testDeleteWithForceFlag(): void
    {
        $date = Carbon::today();
        $timesheet = TimesheetFactory::create(
            $this->activity,
            'Test description',
            null,
            2.5,
            $date,
            $this->role,
            false,
            [],
            null
        );

        $this->localRepository
            ->expects($this->once())
            ->method('get')
            ->with($timesheet->uuid)
            ->willReturn($timesheet);

        $this->localRepository
            ->expects($this->once())
            ->method('remove')
            ->with($timesheet->uuid);

        $this->commandTester->execute([
            'timesheet' => $timesheet->uuid,
            '--force' => true
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Timesheet deleted locally', $this->commandTester->getDisplay());
    }

    public function testDeleteCancelled(): void
    {
        $date = Carbon::today();
        $timesheet = TimesheetFactory::create(
            $this->activity,
            'Test description',
            null,
            2.5,
            $date,
            $this->role,
            false,
            [],
            null
        );

        $this->localRepository
            ->expects($this->once())
            ->method('get')
            ->with($timesheet->uuid)
            ->willReturn($timesheet);

        $this->localRepository
            ->expects($this->never())
            ->method('remove');

        $this->commandTester->setInputs(['no']); // Cancel deletion
        $this->commandTester->execute(['timesheet' => $timesheet->uuid]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Deletion cancelled', $this->commandTester->getDisplay());
    }

    public function testDeleteRemoteFailureStillDeletesLocally(): void
    {
        $date = Carbon::today();
        $timesheet = TimesheetFactory::create(
            $this->activity,
            'Test description',
            null,
            2.5,
            $date,
            $this->role,
            false,
            [],
            12345
        );

        $this->localRepository
            ->expects($this->once())
            ->method('get')
            ->with($timesheet->uuid)
            ->willReturn($timesheet);

        $this->zebraRepository
            ->expects($this->once())
            ->method('delete')
            ->willReturn(false); // Deletion cancelled/failed

        $this->localRepository
            ->expects($this->once())
            ->method('remove')
            ->with($timesheet->uuid);

        $this->commandTester->setInputs(['yes', 'yes']);
        $this->commandTester->execute(['timesheet' => $timesheet->uuid]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Failed to delete timesheet from Zebra', $this->commandTester->getDisplay());
        $this->assertStringContainsString('Timesheet deleted locally', $this->commandTester->getDisplay());
    }

    public function testDeleteRemoteExceptionStillDeletesLocally(): void
    {
        $date = Carbon::today();
        $timesheet = TimesheetFactory::create(
            $this->activity,
            'Test description',
            null,
            2.5,
            $date,
            $this->role,
            false,
            [],
            12345
        );

        $this->localRepository
            ->expects($this->once())
            ->method('get')
            ->with($timesheet->uuid)
            ->willReturn($timesheet);

        $this->zebraRepository
            ->expects($this->once())
            ->method('delete')
            ->willThrowException(new ZebraApiException('Failed to delete timesheet via Zebra API: Client error'));

        $this->localRepository
            ->expects($this->once())
            ->method('remove')
            ->with($timesheet->uuid);

        $this->commandTester->setInputs(['yes', 'yes']);
        $this->commandTester->execute(['timesheet' => $timesheet->uuid]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $normalizedOutput = preg_replace('/\s+/', ' ', str_replace(["\n", "\r"], ' ', $output));
        $this->assertStringContainsString('Failed to delete timesheet from Zebra', $normalizedOutput);
        $this->assertStringContainsString('Proceeding with local deletion anyway', $normalizedOutput);
        $this->assertStringContainsString('Timesheet deleted locally', $output);
    }

    public function testDeleteRemoteGenericExceptionStillDeletesLocally(): void
    {
        $date = Carbon::today();
        $timesheet = TimesheetFactory::create(
            $this->activity,
            'Test description',
            null,
            2.5,
            $date,
            $this->role,
            false,
            [],
            12345
        );

        $this->localRepository
            ->expects($this->once())
            ->method('get')
            ->with($timesheet->uuid)
            ->willReturn($timesheet);

        $this->zebraRepository
            ->expects($this->once())
            ->method('delete')
            ->willThrowException(new Exception('Unexpected error'));

        $this->localRepository
            ->expects($this->once())
            ->method('remove')
            ->with($timesheet->uuid);

        $this->commandTester->setInputs(['yes', 'yes']);
        $this->commandTester->execute(['timesheet' => $timesheet->uuid]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $normalizedOutput = preg_replace('/\s+/', ' ', str_replace(["\n", "\r"], ' ', $output));
        $this->assertStringContainsString('Failed to delete timesheet from Zebra', $normalizedOutput);
        $this->assertStringContainsString('Proceeding with local deletion anyway', $normalizedOutput);
        $this->assertStringContainsString('Timesheet deleted locally', $output);
    }
}

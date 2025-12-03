<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tcrawf\Zebra\Command\FramesCommand;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Frame\FrameRepositoryInterface;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Tests\Helper\CommandTestTrait;
use Tcrawf\Zebra\Tests\Helper\TestEntityFactory;
use Tcrawf\Zebra\Uuid\Uuid;

class FramesCommandTest extends TestCase
{
    use CommandTestTrait;

    private FrameRepositoryInterface&MockObject $frameRepository;
    private FramesCommand $command;
    private CommandTester $commandTester;
    private Activity $activity;
    private Role $role;

    protected function setUp(): void
    {
        $this->frameRepository = $this->createMock(FrameRepositoryInterface::class);
        $this->command = new FramesCommand($this->frameRepository);
        $this->commandTester = $this->setupCommandTester($this->command);
        $this->activity = TestEntityFactory::createActivity();
        $this->role = TestEntityFactory::createRole();
    }

    public function testFramesCommandExists(): void
    {
        $this->assertInstanceOf(FramesCommand::class, $this->command);
    }

    public function testFramesCommandName(): void
    {
        $this->assertEquals('frames', $this->command->getName());
    }

    public function testFramesCommandDescription(): void
    {
        $this->assertNotEmpty($this->command->getDescription());
    }

    public function testDisplayFramesWhenFramesExist(): void
    {
        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();
        $start1 = Carbon::now()->subHours(2);
        $start2 = Carbon::now()->subHour();
        $frame1 = TestEntityFactory::createFrame(
            $uuid1,
            $start1,
            $start1->copy()->addHour(),
            $this->activity,
            false,
            $this->role
        );
        $frame2 = TestEntityFactory::createFrame(
            $uuid2,
            $start2,
            $start2->copy()->addHour(),
            $this->activity,
            false,
            $this->role
        );

        $this->frameRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn([$frame1, $frame2]);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString($uuid2->getHex(), $output); // Most recent first
        $this->assertStringContainsString($uuid1->getHex(), $output);
    }

    public function testDisplayNoFramesMessageWhenNoFrames(): void
    {
        $this->frameRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn([]);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('No frames found', $output);
    }

    public function testFramesAreSortedByStartTimeDescending(): void
    {
        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();
        $uuid3 = Uuid::random();
        $start1 = Carbon::now()->subDays(3);
        $start2 = Carbon::now()->subDays(1);
        $start3 = Carbon::now()->subHours(2);
        $frame1 = TestEntityFactory::createFrame(
            $uuid1,
            $start1,
            $start1->copy()->addHour(),
            $this->activity,
            false,
            $this->role
        );
        $frame2 = TestEntityFactory::createFrame(
            $uuid2,
            $start2,
            $start2->copy()->addHour(),
            $this->activity,
            false,
            $this->role
        );
        $frame3 = TestEntityFactory::createFrame(
            $uuid3,
            $start3,
            $start3->copy()->addHour(),
            $this->activity,
            false,
            $this->role
        );

        $this->frameRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn([$frame1, $frame2, $frame3]);

        $this->commandTester->execute([]);

        $output = $this->commandTester->getDisplay();
        $lines = array_filter(
            explode("\n", $output),
            fn($line) => !empty(trim($line)) && !str_contains($line, 'No frames')
        );
        $frameLines = array_values($lines);

        // Most recent should be first
        $this->assertStringContainsString($uuid3->getHex(), $frameLines[0]);
        $this->assertStringContainsString($uuid2->getHex(), $frameLines[1]);
        $this->assertStringContainsString($uuid1->getHex(), $frameLines[2]);
    }
}

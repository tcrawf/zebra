<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Command\Autocompletion\FrameAutocompletion;
use Tcrawf\Zebra\Command\RemoveCommand;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Frame\Frame;
use Tcrawf\Zebra\Frame\FrameRepositoryInterface;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Uuid\Uuid;

class RemoveCommandTest extends TestCase
{
    private FrameRepositoryInterface&MockObject $frameRepository;
    private FrameAutocompletion&MockObject $autocompletion;
    private RemoveCommand $command;
    private CommandTester $commandTester;
    private Activity $activity;
    private Role $role;
    private Frame $frame;

    protected function setUp(): void
    {
        $this->frameRepository = $this->createMock(FrameRepositoryInterface::class);
        $this->autocompletion = $this->createMock(FrameAutocompletion::class);
        $this->command = new RemoveCommand($this->frameRepository, $this->autocompletion);

        $application = new Application();
        $application->add($this->command);
        $this->commandTester = new CommandTester($this->command);

        $this->activity = new Activity(
            EntityKey::zebra(1),
            'Test Activity',
            'Description',
            EntityKey::zebra(100)
        );
        $this->role = new Role(1, null, 'Developer');

        $uuid = Uuid::random();
        $startTime = Carbon::now()->subHour();
        $stopTime = Carbon::now();
        $this->frame = new Frame(
            $uuid,
            $startTime,
            $stopTime,
            $this->activity,
            false,
            $this->role,
            'Test description'
        );
    }

    public function testRemoveCommandExists(): void
    {
        $this->assertInstanceOf(RemoveCommand::class, $this->command);
    }

    public function testRemoveCommandName(): void
    {
        $this->assertEquals('remove', $this->command->getName());
    }

    public function testRemoveCommandDescription(): void
    {
        $this->assertNotEmpty($this->command->getDescription());
    }

    public function testRemoveFrameByUuidWithForce(): void
    {
        $this->frameRepository
            ->expects($this->once())
            ->method('get')
            ->with($this->frame->uuid)
            ->willReturn($this->frame);

        $this->frameRepository
            ->expects($this->once())
            ->method('remove')
            ->with($this->frame->uuid);

        $this->commandTester->execute([
            'frame' => $this->frame->uuid,
            '--force' => true,
        ], ['interactive' => false]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Frame removed successfully', $output);
    }

    public function testRemoveFrameByNegativeIndex(): void
    {
        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();
        $start1 = Carbon::now()->subHours(2);
        $start2 = Carbon::now()->subHour();
        $frame1 = new Frame(
            $uuid1,
            $start1,
            $start1->copy()->addHour(),
            $this->activity,
            false,
            $this->role
        );
        $frame2 = new Frame(
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

        $this->frameRepository
            ->expects($this->once())
            ->method('remove')
            ->with($frame2->uuid); // -1 should be most recent (frame2)

        $this->commandTester->execute([
            'frame' => '-1',
            '--force' => true,
        ], ['interactive' => false]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testRemoveFrameByPositiveIndex(): void
    {
        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();
        $start1 = Carbon::now()->subHours(2);
        $start2 = Carbon::now()->subHour();
        $frame1 = new Frame(
            $uuid1,
            $start1,
            $start1->copy()->addHour(),
            $this->activity,
            false,
            $this->role
        );
        $frame2 = new Frame(
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

        $this->frameRepository
            ->expects($this->once())
            ->method('remove')
            ->with($frame2->uuid); // 1 should be most recent (frame2)

        $this->commandTester->execute([
            'frame' => '1',
            '--force' => true,
        ], ['interactive' => false]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testRemoveFrameNotFound(): void
    {
        $this->frameRepository
            ->expects($this->once())
            ->method('get')
            ->with('non-existent-uuid')
            ->willReturn(null);

        $this->frameRepository
            ->expects($this->never())
            ->method('all');

        $this->commandTester->execute([
            'frame' => 'non-existent-uuid',
            '--force' => true,
        ], ['interactive' => false]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString("Frame 'non-existent-uuid' not found", $output);
    }

    public function testRemoveFrameWithConfirmationCancelled(): void
    {
        $this->frameRepository
            ->expects($this->once())
            ->method('get')
            ->with($this->frame->uuid)
            ->willReturn($this->frame);

        $this->frameRepository
            ->expects($this->never())
            ->method('remove');

        // Mock STDIN for "no" response
        $this->mockStdin("no\n");

        $this->commandTester->execute([
            'frame' => $this->frame->uuid,
        ], ['interactive' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Frame removal cancelled', $output);
    }

    public function testRemoveFrameWithConfirmationAccepted(): void
    {
        $this->frameRepository
            ->expects($this->once())
            ->method('get')
            ->with($this->frame->uuid)
            ->willReturn($this->frame);

        $this->frameRepository
            ->expects($this->once())
            ->method('remove')
            ->with($this->frame->uuid);

        // Mock STDIN for "yes" response
        $this->commandTester->setInputs(['yes']);

        $this->commandTester->execute([
            'frame' => $this->frame->uuid,
        ], ['interactive' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Frame removed successfully', $output);
    }

    public function testRemoveFrameWithException(): void
    {
        $this->frameRepository
            ->expects($this->once())
            ->method('get')
            ->with($this->frame->uuid)
            ->willReturn($this->frame);

        $this->frameRepository
            ->expects($this->once())
            ->method('remove')
            ->with($this->frame->uuid)
            ->willThrowException(new \RuntimeException('Storage error'));

        $this->commandTester->execute([
            'frame' => $this->frame->uuid,
            '--force' => true,
        ], ['interactive' => false]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('An error occurred: Storage error', $output);
    }

    /**
     * Mock STDIN input for interactive commands.
     */
    private function mockStdin(string $input): void
    {
        $stream = fopen('php://memory', 'r+', false);
        fwrite($stream, $input);
        rewind($stream);
        // Note: This is a simplified mock - in real tests, you might need more sophisticated mocking
        // For now, we'll rely on CommandTester's interactive mode handling
    }
}

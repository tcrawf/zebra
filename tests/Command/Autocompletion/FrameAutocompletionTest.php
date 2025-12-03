<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command\Autocompletion;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Tcrawf\Zebra\Command\Autocompletion\FrameAutocompletion;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Frame\Frame;
use Tcrawf\Zebra\Frame\FrameRepositoryInterface;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Timezone\TimezoneFormatter;
use Tcrawf\Zebra\Uuid\Uuid;

class FrameAutocompletionTest extends TestCase
{
    private FrameRepositoryInterface&MockObject $frameRepository;
    private TimezoneFormatter&MockObject $timezoneFormatter;
    private FrameAutocompletion $autocompletion;

    protected function setUp(): void
    {
        $this->frameRepository = $this->createMock(FrameRepositoryInterface::class);
        $this->timezoneFormatter = $this->createMock(TimezoneFormatter::class);
        $this->autocompletion = new FrameAutocompletion(
            $this->frameRepository,
            $this->timezoneFormatter
        );
    }

    /**
     * Create a CompletionInput with the specified completion value.
     *
     * @param string $completionValue
     * @return CompletionInput
     */
    private function createCompletionInput(string $completionValue): CompletionInput
    {
        $input = CompletionInput::fromString('command', 7);
        $reflection = new \ReflectionClass($input);
        $property = $reflection->getProperty('completionValue');
        $property->setAccessible(true);
        $property->setValue($input, $completionValue);
        return $input;
    }

    public function testSuggestWithNegativeIndexPattern(): void
    {
        $this->frameRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn([]);

        $input = $this->createCompletionInput('-');

        $suggestions = new CompletionSuggestions();
        $this->autocompletion->suggest($input, $suggestions);

        $values = $suggestions->getValueSuggestions();
        $this->assertGreaterThanOrEqual(1, count($values));
    }

    public function testSuggestWithMatchingUuid(): void
    {
        $uuid = Uuid::random();
        $activity = new Activity(EntityKey::zebra(1), 'Activity', 'Description', EntityKey::zebra(100), 'alias');
        $startTime = Carbon::now();
        $role = new \Tcrawf\Zebra\Role\Role(1, null, 'Developer', 'Developer', 'employee', 'active');
        $frame = new Frame($uuid, $startTime, null, $activity, false, $role, 'Description');

        $this->frameRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn([$frame]);

        $this->timezoneFormatter
            ->method('toLocal')
            ->willReturn($startTime);

        $input = $this->createCompletionInput(substr($uuid->toString(), 0, 8));

        $suggestions = new CompletionSuggestions();
        $this->autocompletion->suggest($input, $suggestions);

        $values = $suggestions->getValueSuggestions();
        $this->assertGreaterThanOrEqual(1, count($values));
    }

    public function testSuggestSortsByStartTimeDescending(): void
    {
        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();
        $activity = new Activity(EntityKey::zebra(1), 'Activity', 'Description', EntityKey::zebra(100));
        $role = new \Tcrawf\Zebra\Role\Role(1, null, 'Developer', 'Developer', 'employee', 'active');

        $frame1 = new Frame($uuid1, Carbon::now()->subDay(), null, $activity, false, $role, 'Older');
        $frame2 = new Frame($uuid2, Carbon::now(), null, $activity, false, $role, 'Newer');

        $this->frameRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn([$frame1, $frame2]);

        $this->timezoneFormatter
            ->method('toLocal')
            ->willReturnCallback(fn($time) => $time);

        $input = $this->createCompletionInput('');

        $suggestions = new CompletionSuggestions();
        $this->autocompletion->suggest($input, $suggestions);

        $values = $suggestions->getValueSuggestions();
        $this->assertGreaterThanOrEqual(2, count($values));
    }
}

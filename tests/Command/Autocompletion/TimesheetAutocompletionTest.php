<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command\Autocompletion;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Tcrawf\Zebra\Command\Autocompletion\TimesheetAutocompletion;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Role\Role;
use Tcrawf\Zebra\Timesheet\LocalTimesheetRepositoryInterface;
use Tcrawf\Zebra\Timesheet\TimesheetFactory;
use Tcrawf\Zebra\Uuid\Uuid;

class TimesheetAutocompletionTest extends TestCase
{
    private LocalTimesheetRepositoryInterface&MockObject $timesheetRepository;
    private TimesheetAutocompletion $autocompletion;

    protected function setUp(): void
    {
        $this->timesheetRepository = $this->createMock(LocalTimesheetRepositoryInterface::class);
        $this->autocompletion = new TimesheetAutocompletion($this->timesheetRepository);
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

    public function testSuggestWithMatchingUuid(): void
    {
        $uuid = Uuid::random();
        $activity = new Activity(EntityKey::zebra(1), 'Activity', 'Description', EntityKey::zebra(100), 'alias');
        $role = new Role(1, null, 'Developer', 'Developer', 'employee', 'active');
        $timesheet = TimesheetFactory::create(
            $activity,
            'Description',
            null,
            1.5,
            Carbon::now(),
            $role,
            false,
            [],
            null,
            null,
            $uuid
        );

        $this->timesheetRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn([$timesheet]);

        $input = $this->createCompletionInput(substr($uuid->toString(), 0, 8));

        $suggestions = new CompletionSuggestions();
        $this->autocompletion->suggest($input, $suggestions);

        $values = $suggestions->getValueSuggestions();
        $this->assertCount(1, $values);
    }

    public function testSuggestSortsByDateDescending(): void
    {
        $uuid1 = Uuid::random();
        $uuid2 = Uuid::random();
        $activity = new Activity(EntityKey::zebra(1), 'Activity', 'Description', EntityKey::zebra(100));
        $role = new Role(1, null, 'Developer', 'Developer', 'employee', 'active');

        $timesheet1 = TimesheetFactory::create(
            $activity,
            'Older',
            null,
            1.0,
            Carbon::now()->subDay(),
            $role,
            false,
            [],
            null,
            null,
            $uuid1
        );
        $timesheet2 = TimesheetFactory::create(
            $activity,
            'Newer',
            null,
            1.0,
            Carbon::now(),
            $role,
            false,
            [],
            null,
            null,
            $uuid2
        );

        $this->timesheetRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn([$timesheet1, $timesheet2]);

        $input = $this->createCompletionInput('');

        $suggestions = new CompletionSuggestions();
        $this->autocompletion->suggest($input, $suggestions);

        $values = $suggestions->getValueSuggestions();
        $this->assertGreaterThanOrEqual(2, count($values));
    }
}

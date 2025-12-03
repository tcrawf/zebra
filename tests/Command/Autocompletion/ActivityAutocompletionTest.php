<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command\Autocompletion;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Completion\Suggestion;
use Tcrawf\Zebra\Command\Autocompletion\ActivityAutocompletion;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Activity\Activity;
use Tcrawf\Zebra\Project\Project;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;

class ActivityAutocompletionTest extends TestCase
{
    private ProjectRepositoryInterface&MockObject $projectRepository;
    private ActivityAutocompletion $autocompletion;

    protected function setUp(): void
    {
        $this->projectRepository = $this->createMock(ProjectRepositoryInterface::class);
        $this->autocompletion = new ActivityAutocompletion($this->projectRepository);
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

    public function testSuggestPrioritizesAliases(): void
    {
        $activities = [
            new Activity(EntityKey::zebra(1), 'Activity Name', 'Description', EntityKey::zebra(100), 'alias1'),
            new Activity(EntityKey::zebra(2), 'Activity Name', 'Description', EntityKey::zebra(100)),
        ];

        $project = new Project(EntityKey::zebra(100), 'Project', 'Description', 1, $activities);
        $projects = [$project];

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn($projects);

        $input = $this->createCompletionInput('alias');

        $suggestions = new CompletionSuggestions();
        $this->autocompletion->suggest($input, $suggestions);

        $values = $suggestions->getValueSuggestions();
        // Extract string values from Suggestion objects
        $valueStrings = array_map(
            static fn($v) => $v instanceof Suggestion ? $v->getValue() : $v,
            $values
        );
        $this->assertContains('alias1', $valueStrings);
    }

    public function testSuggestWithActivityNames(): void
    {
        $activities = [
            new Activity(EntityKey::zebra(1), 'Test Activity', 'Description', EntityKey::zebra(100)),
        ];

        $project = new Project(EntityKey::zebra(100), 'Project', 'Description', 1, $activities);
        $projects = [$project];

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn($projects);

        $input = $this->createCompletionInput('Test');

        $suggestions = new CompletionSuggestions();
        $this->autocompletion->suggest($input, $suggestions);

        $values = $suggestions->getValueSuggestions();
        // Extract string values from Suggestion objects
        $valueStrings = array_map(
            static fn($v) => $v instanceof Suggestion ? $v->getValue() : $v,
            $values
        );
        $this->assertContains('Test Activity', $valueStrings);
    }

    public function testSuggestDeduplicatesNamesAndAliases(): void
    {
        $activities = [
            new Activity(EntityKey::zebra(1), 'Same Name', 'Description', EntityKey::zebra(100), 'Same Name'),
        ];

        $project = new Project(EntityKey::zebra(100), 'Project', 'Description', 1, $activities);
        $projects = [$project];

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn($projects);

        $input = $this->createCompletionInput('Same');

        $suggestions = new CompletionSuggestions();
        $this->autocompletion->suggest($input, $suggestions);

        $values = $suggestions->getValueSuggestions();
        $this->assertCount(1, $values);
    }
}

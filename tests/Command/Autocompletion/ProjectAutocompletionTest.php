<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command\Autocompletion;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Tcrawf\Zebra\Command\Autocompletion\ProjectAutocompletion;
use Tcrawf\Zebra\EntityKey\EntityKey;
use Tcrawf\Zebra\Project\Project;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;

class ProjectAutocompletionTest extends TestCase
{
    private ProjectRepositoryInterface&MockObject $projectRepository;
    private ProjectAutocompletion $autocompletion;

    protected function setUp(): void
    {
        $this->projectRepository = $this->createMock(ProjectRepositoryInterface::class);
        $this->autocompletion = new ProjectAutocompletion($this->projectRepository);
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

    public function testSuggestWithMatchingProjects(): void
    {
        $projects = [
            new Project(EntityKey::zebra(1), 'Test Project', 'Description', 1, []),
            new Project(EntityKey::zebra(2), 'Another Project', 'Description', 1, []),
            new Project(EntityKey::zebra(3), 'Different Project', 'Description', 1, []),
        ];

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn($projects);

        $input = $this->createCompletionInput('Test');

        $suggestions = new CompletionSuggestions();
        $this->autocompletion->suggest($input, $suggestions);

        $values = $suggestions->getValueSuggestions();
        $this->assertCount(1, $values);
        $this->assertEquals('Test Project', $values[0]);
    }

    public function testSuggestWithCaseInsensitiveMatching(): void
    {
        $projects = [
            new Project(EntityKey::zebra(1), 'Test Project', 'Description', 1, []),
        ];

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn($projects);

        $input = $this->createCompletionInput('test');

        $suggestions = new CompletionSuggestions();
        $this->autocompletion->suggest($input, $suggestions);

        $values = $suggestions->getValueSuggestions();
        $this->assertCount(1, $values);
    }

    public function testSuggestWithNoMatches(): void
    {
        $projects = [
            new Project(EntityKey::zebra(1), 'Test Project', 'Description', 1, []),
        ];

        $this->projectRepository
            ->expects($this->once())
            ->method('all')
            ->willReturn($projects);

        $input = $this->createCompletionInput('XYZ');

        $suggestions = new CompletionSuggestions();
        $this->autocompletion->suggest($input, $suggestions);

        $values = $suggestions->getValueSuggestions();
        $this->assertCount(0, $values);
    }
}

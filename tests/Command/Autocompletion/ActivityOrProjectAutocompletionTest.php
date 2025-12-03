<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command\Autocompletion;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Tcrawf\Zebra\Command\Autocompletion\ActivityAutocompletion;
use Tcrawf\Zebra\Command\Autocompletion\ActivityOrProjectAutocompletion;
use Tcrawf\Zebra\Command\Autocompletion\ProjectAutocompletion;

class ActivityOrProjectAutocompletionTest extends TestCase
{
    private ActivityAutocompletion&MockObject $activityAutocompletion;
    private ProjectAutocompletion&MockObject $projectAutocompletion;
    private ActivityOrProjectAutocompletion $autocompletion;

    protected function setUp(): void
    {
        $this->activityAutocompletion = $this->createMock(ActivityAutocompletion::class);
        $this->projectAutocompletion = $this->createMock(ProjectAutocompletion::class);
        $this->autocompletion = new ActivityOrProjectAutocompletion(
            $this->activityAutocompletion,
            $this->projectAutocompletion
        );
    }

    public function testSuggestCallsBothAutocompletions(): void
    {
        $input = CompletionInput::fromString('command', 7);
        $suggestions = new CompletionSuggestions();

        $this->activityAutocompletion
            ->expects($this->once())
            ->method('suggest')
            ->with($input, $suggestions);

        $this->projectAutocompletion
            ->expects($this->once())
            ->method('suggest')
            ->with($input, $suggestions);

        $this->autocompletion->suggest($input, $suggestions);
    }
}

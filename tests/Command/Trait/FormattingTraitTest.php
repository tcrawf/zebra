<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command\Trait;

use PHPUnit\Framework\TestCase;
use Tcrawf\Zebra\Command\Trait\FormattingTrait;

class FormattingTraitTest extends TestCase
{
    private object $command;

    protected function setUp(): void
    {
        $this->command = new class {
            use FormattingTrait;
        };
    }

    public function testFormatDurationWithoutSecondsWithHours(): void
    {
        $result = $this->callPrivateMethod('formatDurationWithoutSeconds', [3661]); // 1h 1m 1s
        $this->assertEquals('1h 1m', $result);
    }

    public function testFormatDurationWithoutSecondsWithMinutesOnly(): void
    {
        $result = $this->callPrivateMethod('formatDurationWithoutSeconds', [120]); // 2m
        $this->assertEquals('2m', $result);
    }

    public function testFormatDurationWithoutSecondsWithZero(): void
    {
        $result = $this->callPrivateMethod('formatDurationWithoutSeconds', [0]);
        $this->assertEquals('0m', $result);
    }

    public function testEscapeCsvWithComma(): void
    {
        $result = $this->callPrivateMethod('escapeCsv', ['Test, value']);
        $this->assertEquals('"Test, value"', $result);
    }

    public function testEscapeCsvWithQuote(): void
    {
        $result = $this->callPrivateMethod('escapeCsv', ['Test "value"']);
        $this->assertEquals('"Test ""value"""', $result);
    }

    public function testEscapeCsvWithNewline(): void
    {
        $result = $this->callPrivateMethod('escapeCsv', ["Test\nvalue"]);
        $this->assertEquals("\"Test\nvalue\"", $result);
    }

    public function testEscapeCsvWithoutSpecialChars(): void
    {
        $result = $this->callPrivateMethod('escapeCsv', ['Test value']);
        $this->assertEquals('Test value', $result);
    }

    public function testAbbreviateProjectName(): void
    {
        $result = $this->callPrivateMethod('abbreviateProjectName', ['Very Long Project Name That Exceeds Limit']);
        $this->assertLessThanOrEqual(20, mb_strlen($result));
    }

    public function testAbbreviateProjectNameWithinLimit(): void
    {
        $result = $this->callPrivateMethod('abbreviateProjectName', ['Short Name']);
        $this->assertEquals('Short Name', $result);
    }

    public function testDeduplicateDescriptions(): void
    {
        $descriptions = ['A', 'B', 'A', 'C', 'B'];
        $result = $this->callPrivateMethod('deduplicateDescriptions', [$descriptions]);
        $this->assertEquals(['A', 'B', 'C'], $result);
    }

    public function testDeduplicateDescriptionsPreservesOrder(): void
    {
        $descriptions = ['First', 'Second', 'First', 'Third'];
        $result = $this->callPrivateMethod('deduplicateDescriptions', [$descriptions]);
        $this->assertEquals(['First', 'Second', 'Third'], $result);
    }

    /**
     * Helper to call private methods for testing.
     */
    private function callPrivateMethod(string $methodName, array $args)
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($this->command, $args);
    }
}

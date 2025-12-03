<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command\Trait;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Tcrawf\Zebra\Command\Trait\CommandInputParserTrait;

class CommandInputParserTraitTest extends TestCase
{
    private object $command;

    protected function setUp(): void
    {
        $this->command = new class {
            use CommandInputParserTrait;

            protected function getDefaultReverseOrder(): bool
            {
                return false;
            }
        };
    }

    public function testParseIntArrayWithValues(): void
    {
        $result = $this->callPrivateMethod('parseIntArray', [['1', '2', '3']]);
        $this->assertEquals([1, 2, 3], $result);
    }

    public function testParseIntArrayWithEmptyArray(): void
    {
        $result = $this->callPrivateMethod('parseIntArray', [[]]);
        $this->assertNull($result);
    }

    public function testShouldIncludeCurrentWithCurrentOption(): void
    {
        $definition = new InputDefinition([
            new InputOption('current', null, InputOption::VALUE_NONE),
            new InputOption('no-current', null, InputOption::VALUE_NONE),
        ]);

        $input = new ArrayInput(['--current' => true], $definition);
        $result = $this->callPrivateMethod('shouldIncludeCurrent', [$input]);
        $this->assertTrue($result);
    }

    public function testShouldIncludeCurrentWithNoCurrentOption(): void
    {
        $definition = new InputDefinition([
            new InputOption('current', null, InputOption::VALUE_NONE),
            new InputOption('no-current', null, InputOption::VALUE_NONE),
        ]);

        $input = new ArrayInput(['--no-current' => true], $definition);
        $result = $this->callPrivateMethod('shouldIncludeCurrent', [$input]);
        $this->assertFalse($result);
    }

    public function testShouldIncludeCurrentDefaultsToFalse(): void
    {
        $definition = new InputDefinition([
            new InputOption('current', null, InputOption::VALUE_NONE),
            new InputOption('no-current', null, InputOption::VALUE_NONE),
        ]);

        $input = new ArrayInput([], $definition);
        $result = $this->callPrivateMethod('shouldIncludeCurrent', [$input]);
        $this->assertFalse($result);
    }

    public function testShouldReverseWithReverseOption(): void
    {
        $definition = new InputDefinition([
            new InputOption('reverse', null, InputOption::VALUE_NONE),
            new InputOption('no-reverse', null, InputOption::VALUE_NONE),
        ]);

        $input = new ArrayInput(['--reverse' => true], $definition);
        $result = $this->callPrivateMethod('shouldReverse', [$input]);
        $this->assertTrue($result);
    }

    public function testShouldReverseWithNoReverseOption(): void
    {
        $definition = new InputDefinition([
            new InputOption('reverse', null, InputOption::VALUE_NONE),
            new InputOption('no-reverse', null, InputOption::VALUE_NONE),
        ]);

        $input = new ArrayInput(['--no-reverse' => true], $definition);
        $result = $this->callPrivateMethod('shouldReverse', [$input]);
        $this->assertFalse($result);
    }

    public function testGetOutputFormatJson(): void
    {
        $definition = new InputDefinition([
            new InputOption('json', null, InputOption::VALUE_NONE),
            new InputOption('csv', null, InputOption::VALUE_NONE),
        ]);

        $input = new ArrayInput(['--json' => true], $definition);
        $result = $this->callPrivateMethod('getOutputFormat', [$input]);
        $this->assertEquals('json', $result);
    }

    public function testGetOutputFormatCsv(): void
    {
        $definition = new InputDefinition([
            new InputOption('json', null, InputOption::VALUE_NONE),
            new InputOption('csv', null, InputOption::VALUE_NONE),
        ]);

        $input = new ArrayInput(['--csv' => true], $definition);
        $result = $this->callPrivateMethod('getOutputFormat', [$input]);
        $this->assertEquals('csv', $result);
    }

    public function testGetOutputFormatDefaultsToPlain(): void
    {
        $definition = new InputDefinition([
            new InputOption('json', null, InputOption::VALUE_NONE),
            new InputOption('csv', null, InputOption::VALUE_NONE),
        ]);

        $input = new ArrayInput([], $definition);
        $result = $this->callPrivateMethod('getOutputFormat', [$input]);
        $this->assertEquals('plain', $result);
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

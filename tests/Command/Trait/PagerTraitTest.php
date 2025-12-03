<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Command\Trait;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Tcrawf\Zebra\Command\Trait\PagerTrait;

class PagerTraitTest extends TestCase
{
    private object $command;

    protected function setUp(): void
    {
        $this->command = new class {
            use PagerTrait;

            protected function getDefaultPagerBehavior(): bool
            {
                return true;
            }
        };
    }

    public function testShouldUsePagerWithPagerOption(): void
    {
        $definition = new InputDefinition([
            new InputOption('pager', null, InputOption::VALUE_NONE),
            new InputOption('no-pager', null, InputOption::VALUE_NONE),
        ]);

        $input = new ArrayInput(['--pager' => true], $definition);
        $result = $this->callPrivateMethod('shouldUsePager', [$input]);
        $this->assertTrue($result);
    }

    public function testShouldUsePagerWithNoPagerOption(): void
    {
        $definition = new InputDefinition([
            new InputOption('pager', null, InputOption::VALUE_NONE),
            new InputOption('no-pager', null, InputOption::VALUE_NONE),
        ]);

        $input = new ArrayInput(['--no-pager' => true], $definition);
        $result = $this->callPrivateMethod('shouldUsePager', [$input]);
        $this->assertFalse($result);
    }

    public function testShouldUsePagerDefaultsToTrue(): void
    {
        $definition = new InputDefinition([
            new InputOption('pager', null, InputOption::VALUE_NONE),
            new InputOption('no-pager', null, InputOption::VALUE_NONE),
        ]);

        $input = new ArrayInput([], $definition);
        $result = $this->callPrivateMethod('shouldUsePager', [$input]);
        $this->assertTrue($result);
    }

    public function testValidatePagerCommandWithAllowedPager(): void
    {
        $result = $this->callPrivateMethod('validatePagerCommand', ['less']);
        $this->assertEquals('less', $result);
    }

    public function testValidatePagerCommandWithShellMetacharacters(): void
    {
        $result = $this->callPrivateMethod('validatePagerCommand', ['less; rm -rf /']);
        $this->assertNull($result);
    }

    public function testValidatePagerCommandWithInvalidCommand(): void
    {
        $result = $this->callPrivateMethod('validatePagerCommand', ['invalid|command']);
        $this->assertNull($result);
    }

    public function testDisplayViaPagerInTestEnvironment(): void
    {
        $output = new BufferedOutput();
        $this->callPrivateMethod('displayViaPager', ['Test content', $output]);
        // writeln adds a newline, so output will be "Test content\n"
        $this->assertEquals("Test content\n", $output->fetch());
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

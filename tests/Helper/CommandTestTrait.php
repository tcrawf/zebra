<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Helper;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Trait for setting up command testers in tests.
 */
trait CommandTestTrait
{
    /**
     * Set up a command tester for the given command.
     *
     * @param Command $command The command to test
     * @return CommandTester
     */
    protected function setupCommandTester(Command $command): CommandTester
    {
        $application = new Application();
        $application->add($command);

        return new CommandTester($command);
    }
}

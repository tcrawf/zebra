<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command\Trait;

use Symfony\Component\Console\Input\InputInterface;

/**
 * Trait for parsing common command input options.
 */
trait CommandInputParserTrait
{
    /**
     * Parse array of integers from option.
     *
     * @param array<string> $values
     * @return array<int>|null
     */
    private function parseIntArray(array $values): ?array
    {
        if (empty($values)) {
            return null;
        }

        return array_map('intval', $values);
    }

    /**
     * Determine if current frame should be included.
     *
     * @param InputInterface $input
     * @return bool
     */
    private function shouldIncludeCurrent(InputInterface $input): bool
    {
        if ($input->getOption('current')) {
            return true;
        }

        if ($input->getOption('no-current')) {
            return false;
        }

        // Default: don't include current frame
        return false;
    }

    /**
     * Determine if order should be reversed.
     *
     * @param InputInterface $input
     * @return bool
     */
    private function shouldReverse(InputInterface $input): bool
    {
        if ($input->getOption('reverse')) {
            return true;
        }

        if ($input->getOption('no-reverse')) {
            return false;
        }

        // Default behavior can be overridden
        return $this->getDefaultReverseOrder();
    }

    /**
     * Get default reverse order behavior.
     * Override to customize default (LogCommand defaults to true, AggregateCommand to false).
     *
     * @return bool
     */
    protected function getDefaultReverseOrder(): bool
    {
        return false;
    }

    /**
     * Get output format.
     *
     * @param InputInterface $input
     * @return string
     */
    private function getOutputFormat(InputInterface $input): string
    {
        if ($input->getOption('json')) {
            return 'json';
        }

        if ($input->getOption('csv')) {
            return 'csv';
        }

        return 'plain';
    }
}

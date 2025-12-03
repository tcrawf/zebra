<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command\Trait;

use Symfony\Component\Console\Input\InputInterface;

/**
 * Trait for parsing command arguments with +description syntax.
 */
trait ArgumentParsingTrait
{
    /**
     * Parse activity arguments and extract description from + syntax.
     * Returns an array with 'activity' and 'description' keys.
     *
     * @param InputInterface $input
     * @return array{activity: string|null, description: string|null}
     */
    protected function parseActivityArguments(InputInterface $input): array
    {
        $args = $input->getArgument('activity');
        // Ensure args is always an array (IS_ARRAY should guarantee this, but ensure type safety)
        if (!is_array($args)) {
            $args = [$args];
        }

        $activityIdentifier = null;
        $descriptionFromPlus = null;
        $plusFound = false;
        $descriptionParts = [];
        $nonPlusArgs = [];

        // Collect all non-+ args and +description parts
        // Args after a + are part of the description
        foreach ($args as $arg) {
            if (str_starts_with($arg, '+')) {
                $plusFound = true;
                // Extract description part (everything after the +)
                $descriptionPart = ltrim($arg, '+');
                if ($descriptionPart !== '') {
                    $descriptionParts[] = $descriptionPart;
                }
            } elseif ($plusFound) {
                // After finding +, all subsequent args are part of description
                $descriptionParts[] = $arg;
            } else {
                // Before finding +, collect as potential activity args
                $nonPlusArgs[] = $arg;
            }
        }

        // Join description parts if any were found
        if (!empty($descriptionParts)) {
            $descriptionFromPlus = implode(' ', $descriptionParts);
        }

        // Find activity identifier
        if (count($nonPlusArgs) === 1) {
            // If there's exactly one non-+ arg, it's the activity
            // (works for both "svv +desc" and "+desc svv")
            $activityIdentifier = $nonPlusArgs[0];
        } elseif (!empty($nonPlusArgs)) {
            // Multiple non-+ args: first one before + is activity, rest after + are description
            // Find where + appears in the original args
            $firstPlusIndex = null;
            foreach ($args as $index => $arg) {
                if (str_starts_with($arg, '+')) {
                    $firstPlusIndex = $index;
                    break;
                }
            }

            if ($firstPlusIndex !== null) {
                // Activity is the last arg before the first +
                $activityIdentifier = $args[$firstPlusIndex - 1] ?? null;
            } else {
                // No + found, first arg is the activity
                $activityIdentifier = $nonPlusArgs[0];
            }
        }

        return [
            'activity' => $activityIdentifier,
            'description' => $descriptionFromPlus,
        ];
    }
}

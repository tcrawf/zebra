<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command\Trait;

/**
 * Trait for common formatting utilities.
 */
trait FormattingTrait
{
    /**
     * Format duration in seconds to human-readable format without seconds.
     *
     * @param int $seconds
     * @return string
     */
    private function formatDurationWithoutSeconds(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        } elseif ($minutes > 0) {
            return sprintf('%dm', $minutes);
        } else {
            return '0m';
        }
    }

    /**
     * Escape CSV field.
     *
     * @param string $field
     * @return string
     */
    private function escapeCsv(string $field): string
    {
        if (str_contains($field, ',') || str_contains($field, '"') || str_contains($field, "\n")) {
            return '"' . str_replace('"', '""', $field) . '"';
        }

        return $field;
    }

    /**
     * Abbreviate project name to specified maximum length.
     *
     * @param string $name
     * @param int $maxLength
     * @return string
     */
    private function abbreviateProjectName(string $name, int $maxLength = 20): string
    {
        if (mb_strlen($name) <= $maxLength) {
            return $name;
        }

        return mb_substr($name, 0, $maxLength);
    }

    /**
     * Remove exact duplicate descriptions from an array, preserving order.
     *
     * @param array<string> $descriptions
     * @return array<string>
     */
    private function deduplicateDescriptions(array $descriptions): array
    {
        $seen = [];
        $result = [];

        foreach ($descriptions as $description) {
            if (!in_array($description, $seen, true)) {
                $seen[] = $description;
                $result[] = $description;
            }
        }

        return $result;
    }
}

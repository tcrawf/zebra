<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Report;

use Carbon\CarbonInterface;

/**
 * Interface for report generation service.
 */
interface ReportServiceInterface
{
    /**
     * Generate a report of time spent grouped by project and activity.
     *
     * @param array<\Tcrawf\Zebra\Frame\FrameInterface> $frames
     * @param CarbonInterface $from Start of date range
     * @param CarbonInterface $to End of date range
     * @return array<string, mixed> Report data structure
     */
    public function generateReport(array $frames, CarbonInterface $from, CarbonInterface $to): array;

    /**
     * Generate a report of time spent grouped by issue-key and activity.
     *
     * @param array<\Tcrawf\Zebra\Frame\FrameInterface> $frames
     * @param CarbonInterface $from Start of date range
     * @param CarbonInterface $to End of date range
     * @return array<string, mixed> Report data structure
     */
    public function generateReportByIssueKey(array $frames, CarbonInterface $from, CarbonInterface $to): array;

    /**
     * Format report as plain text.
     *
     * @param array<string, mixed> $report
     * @return array<string> Lines of formatted output
     */
    public function formatPlainText(array $report): array;

    /**
     * Format report as JSON.
     *
     * @param array<string, mixed> $report
     * @return string JSON string
     */
    public function formatJson(array $report): string;

    /**
     * Format report as CSV.
     *
     * @param array<string, mixed> $report
     * @return string CSV string
     */
    public function formatCsv(array $report): string;

    /**
     * Format report grouped by issue-key as plain text.
     *
     * @param array<string, mixed> $report
     * @return array<string> Lines of formatted output
     */
    public function formatPlainTextByIssueKey(array $report): array;

    /**
     * Format report grouped by issue-key as CSV.
     *
     * @param array<string, mixed> $report
     * @return string CSV string
     */
    public function formatCsvByIssueKey(array $report): string;
}

<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Command\Trait\CommandInputParserTrait;
use Tcrawf\Zebra\Command\Trait\DateRangeParserTrait;
use Tcrawf\Zebra\Command\Trait\PagerTrait;
use Tcrawf\Zebra\Frame\FrameRepositoryInterface;
use Tcrawf\Zebra\Report\ReportServiceInterface;

class ReportCommand extends Command
{
    use CommandInputParserTrait;
    use DateRangeParserTrait;
    use PagerTrait;

    public function __construct(
        private readonly FrameRepositoryInterface $frameRepository,
        private readonly ReportServiceInterface $reportService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('report')
            ->setDescription('Display a report of the time spent on each project')
            ->addOption('from', 'f', InputOption::VALUE_OPTIONAL, 'Start date (ISO 8601 format)', null)
            ->addOption('to', 't', InputOption::VALUE_OPTIONAL, 'End date (ISO 8601 format)', null)
            ->addOption('day', 'd', InputOption::VALUE_NONE, 'Current day')
            ->addOption('week', 'w', InputOption::VALUE_NONE, 'Current week')
            ->addOption('month', 'm', InputOption::VALUE_NONE, 'Current month')
            ->addOption('year', 'y', InputOption::VALUE_NONE, 'Current year')
            ->addOption(
                'project',
                'p',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Filter by project IDs'
            )
            ->addOption(
                'ignore-project',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Exclude project IDs'
            )
            ->addOption(
                'issue-key',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Filter by issue keys'
            )
            ->addOption(
                'ignore-issue-key',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Exclude issue keys'
            )
            ->addOption('current', null, InputOption::VALUE_NONE, 'Include current frame')
            ->addOption('no-current', null, InputOption::VALUE_NONE, 'Do not include current frame')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Format output as JSON')
            ->addOption('csv', null, InputOption::VALUE_NONE, 'Format output as CSV')
            ->addOption('pager', null, InputOption::VALUE_NONE, 'Use pager for output')
            ->addOption('no-pager', null, InputOption::VALUE_NONE, 'Do not use pager');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            // Parse date range
            [$from, $to] = $this->parseDateRange($input);

            // Get filter options
            $projectIds = $this->parseIntArray($input->getOption('project'));
            $ignoreProjectIds = $this->parseIntArray($input->getOption('ignore-project'));
            $issueKeys = $input->getOption('issue-key');
            $ignoreIssueKeys = $input->getOption('ignore-issue-key');
            $includeCurrent = $this->shouldIncludeCurrent($input);

            // Get frames
            $frames = $this->frameRepository->filter(
                $projectIds,
                $issueKeys,
                $ignoreProjectIds,
                $ignoreIssueKeys,
                $from,
                $to,
                true // include partial frames
            );

            // Add current frame if requested
            if ($includeCurrent) {
                $currentFrame = $this->frameRepository->getCurrent();
                if ($currentFrame !== null) {
                    $frames[] = $currentFrame;
                }
            }

            // Generate report
            $report = $this->reportService->generateReport($frames, $from, $to);

            // Format output
            $outputFormat = $this->getOutputFormat($input);
            $outputContent = $this->formatOutput($report, $outputFormat);

            // Display output
            $usePager = $this->shouldUsePager($input);
            if ($usePager && $outputFormat === 'plain' && $output instanceof ConsoleOutputInterface) {
                $output->getErrorOutput()->writeln($outputContent);
            } else {
                $io->writeln($outputContent);
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->writeln('<fg=red>An error occurred: ' . $e->getMessage() . '</fg=red>');
            return Command::FAILURE;
        }
    }

    /**
     * Override default pager behavior.
     * ReportCommand defaults to not using pager.
     */
    protected function getDefaultPagerBehavior(): bool
    {
        return false;
    }

    /**
     * Format output based on format type.
     *
     * @param array<string, mixed> $report
     * @param string $format
     * @return string
     */
    private function formatOutput(array $report, string $format): string
    {
        return match ($format) {
            'json' => $this->reportService->formatJson($report),
            'csv' => $this->reportService->formatCsv($report),
            default => implode("\n", $this->reportService->formatPlainText($report)),
        };
    }
}

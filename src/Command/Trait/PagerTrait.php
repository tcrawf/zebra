<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command\Trait;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tcrawf\Zebra\Command\PhpUnitDetectionTrait;

/**
 * Trait for pager functionality.
 * Requires PhpUnitDetectionTrait for test environment detection.
 */
trait PagerTrait
{
    use PhpUnitDetectionTrait;

    /**
     * Determine if pager should be used.
     *
     * @param InputInterface $input
     * @return bool
     */
    private function shouldUsePager(InputInterface $input): bool
    {
        if ($input->getOption('pager')) {
            return true;
        }

        if ($input->getOption('no-pager')) {
            return false;
        }

        // Default behavior can be overridden
        return $this->getDefaultPagerBehavior();
    }

    /**
     * Get default pager behavior.
     * Override to customize default (LogCommand and AggregateCommand default to true, ReportCommand to false).
     *
     * @return bool
     */
    protected function getDefaultPagerBehavior(): bool
    {
        return true;
    }

    /**
     * Validate and sanitize pager command to prevent command injection.
     *
     * Since we use proc_open() with array syntax, we bypass the shell entirely,
     * so we only need to validate the command name itself, not shell metacharacters.
     *
     * @param string $pager
     * @return string|null Returns validated pager command or null if invalid
     */
    private function validatePagerCommand(string $pager): ?string
    {
        // Whitelist of known safe pager commands
        $allowedPagers = ['less', 'more', 'most', 'pg', 'cat'];

        // Remove any whitespace
        $pager = trim($pager);

        // Explicitly reject shell metacharacters for security
        // Even though we use array syntax with proc_open(), we still reject these for safety
        $shellMetacharacters = [';', '|', '&', '`', '$', '(', ')', '<', '>', "\n", "\r"];
        foreach ($shellMetacharacters as $char) {
            if (str_contains($pager, $char)) {
                return null;
            }
        }

        // Check if it's in the whitelist
        if (in_array($pager, $allowedPagers, true)) {
            return $pager;
        }

        // If not in whitelist, ensure it's a simple command name (no paths, no special chars)
        // Use basename() to extract just the command name if a path was provided
        $commandName = basename($pager);

        // Must be a simple alphanumeric command name with hyphens/underscores only
        if ($commandName !== $pager || !ctype_alnum(str_replace(['-', '_'], '', $commandName))) {
            return null;
        }

        // If validation passes but not in whitelist, default to 'less' for safety
        return 'less';
    }

    /**
     * Display content via pager.
     *
     * @param string $content
     * @param OutputInterface $output
     * @return void
     */
    private function displayViaPager(string $content, OutputInterface $output): void
    {
        // Check if we're running in a test environment
        // In test environments, don't use interactive pagers to prevent hanging
        if ($this->isPhpUnitRunning()) {
            // PHPUnit environment detected - skip pager
            $output->writeln($content);
            return;
        }

        // Check if content fits on one screen - if so, don't use pager
        if ($this->contentFitsOnOneScreen($content)) {
            $output->writeln($content);
            return;
        }

        // Determine pager command (use PAGER env var or default to 'less')
        $pagerRaw = $_ENV['PAGER'] ?? $_SERVER['PAGER'] ?? 'less';

        // Validate and sanitize pager command to prevent command injection
        $pager = $this->validatePagerCommand($pagerRaw);
        if ($pager === null) {
            // Invalid pager, fallback to default
            $pager = 'less';
        }

        // For 'less', add flags for proper pager behavior
        // -X: don't clear screen on exit (preserves terminal state)
        // -R: allow raw control characters (for colors/formatting)
        $pagerCommand = $pager;
        $pagerArgs = [];
        if ($pager === 'less') {
            $pagerArgs = ['-X', '-R'];
        } elseif ($pager === 'more') {
            // 'more' doesn't need special flags
            $pagerArgs = [];
        }

        // Write content to temporary file and use less on that file
        // This approach works better than piping because less can detect it's reading from a file
        // and will open interactively if the output is a TTY
        $tmpFile = tempnam(sys_get_temp_dir(), 'zebra_pager_');
        if ($tmpFile === false) {
            // Fallback to direct output if temp file creation fails
            $output->writeln($content);
            return;
        }

        file_put_contents($tmpFile, $content);

        // Double-check: Verify we're not in a test environment before opening pager
        // This is a defensive check in case something changed between checks
        // @phpstan-ignore-next-line if.alwaysFalse (defensive check - PHPStan can't detect runtime changes)
        if ($this->isPhpUnitRunning()) {
            // Test environment detected - output directly without pager
            $output->writeln($content);
            @unlink($tmpFile);
            return;
        }

        // Use proc_open with STDOUT/STDERR to allow less to detect terminal
        // less will open interactively when reading from a file if stdout is a TTY
        // In test environments, we've already returned above, so this is safe for interactive use
        $descriptorspec = [
            0 => STDIN,  // stdin - less needs this for user interaction
            1 => STDOUT,  // stdout - less writes here
            2 => STDERR,  // stderr - less writes errors here
        ];

        $command = array_merge([$pagerCommand], $pagerArgs, [$tmpFile]);
        $process = @proc_open($command, $descriptorspec, $pipes);

        if (is_resource($process)) {
            // Close any pipes (we don't need them for file-based paging)
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }

            // Wait for process to complete
            proc_close($process);
        } else {
            // Fallback to direct output
            $output->writeln($content);
        }

        // Clean up temp file
        @unlink($tmpFile);
    }

    /**
     * Check if content fits on one screen.
     *
     * @param string $content
     * @return bool True if content fits on one screen, false otherwise
     */
    private function contentFitsOnOneScreen(string $content): bool
    {
        // Count lines in content
        $lineCount = substr_count($content, "\n") + (strlen($content) > 0 && !str_ends_with($content, "\n") ? 1 : 0);

        // Try to get terminal height
        $terminalHeight = $this->getTerminalHeight();

        // If we can't determine terminal height, assume it doesn't fit (use pager to be safe)
        if ($terminalHeight === null) {
            return false;
        }

        // Content fits if it has fewer or equal lines than terminal height
        // Subtract 1 to account for prompt line
        return $lineCount <= ($terminalHeight - 1);
    }

    /**
     * Get terminal height.
     *
     * @return int|null Terminal height in lines, or null if unable to determine
     */
    private function getTerminalHeight(): ?int
    {
        // Try to get terminal size using stty (Unix/Linux/Mac)
        if (PHP_OS_FAMILY !== 'Windows' && function_exists('shell_exec')) {
            $sttyOutput = @shell_exec('stty size 2>/dev/null');
            if ($sttyOutput !== null && preg_match('/\d+\s+(\d+)/', trim($sttyOutput), $matches)) {
                return (int) $matches[1];
            }
        }

        // Try environment variable (some terminals set this)
        $lines = getenv('LINES');
        if ($lines !== false && is_numeric($lines)) {
            return (int) $lines;
        }

        // Try tput (if available)
        if (PHP_OS_FAMILY !== 'Windows' && function_exists('shell_exec')) {
            $tputOutput = @shell_exec('tput lines 2>/dev/null');
            if ($tputOutput !== null && is_numeric(trim($tputOutput))) {
                return (int) trim($tputOutput);
            }
        }

        // Default to 24 lines if we can't determine (common terminal default)
        // Return null to indicate we couldn't determine, so pager will be used
        return null;
    }
}

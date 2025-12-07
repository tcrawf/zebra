<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Config\ConfigFileStorageInterface;

class ConfigCommand extends Command
{
    use PhpUnitDetectionTrait;

    public function __construct(
        private readonly ConfigFileStorageInterface $configStorage
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('config')
            ->setDescription('Get and set configuration options')
            ->addArgument('key', InputArgument::OPTIONAL, 'Configuration key in format section.option')
            ->addArgument('value', InputArgument::OPTIONAL, 'Value to set')
            ->addOption('edit', 'e', InputOption::VALUE_NONE, 'Edit the configuration file with an editor');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $edit = $input->getOption('edit');
        if ($edit) {
            return $this->editConfig($io);
        }

        $key = $input->getArgument('key');
        $value = $input->getArgument('value');

        if ($key === null) {
            $io->text($this->getSynopsis());
            return Command::SUCCESS;
        }

        if ($value === null) {
            // Get value
            $configValue = $this->configStorage->get($key);
            if ($configValue === null) {
                $io->warning("Configuration key '{$key}' not found.");
                return Command::SUCCESS;
            }

            if (is_array($configValue)) {
                $io->writeln(json_encode($configValue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $io->writeln((string) $configValue);
            }

            return Command::SUCCESS;
        }

        // Set value
        // Try to parse as JSON first (for arrays/objects), otherwise use as string
        $decodedValue = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $this->configStorage->set($key, $decodedValue);
        } else {
            $this->configStorage->set($key, $value);
        }

        $io->writeln("<info>Configuration key '{$key}' set to '{$value}'</info>");

        return Command::SUCCESS;
    }

    /**
     * Edit config file in editor.
     *
     * @param SymfonyStyle $io
     * @return int
     */
    private function editConfig(SymfonyStyle $io): int
    {
        $configPath = $this->getConfigFilePath();

        // Read current config
        $config = $this->configStorage->read();
        $jsonContent = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Open in editor
        $editor = $this->getEditor();
        $editedContent = $this->openInEditor($jsonContent, $editor, $configPath);

        if ($editedContent === null || $editedContent === $jsonContent) {
            $io->info('No changes made.');
            return Command::SUCCESS;
        }

        try {
            $editedConfig = json_decode($editedContent, true, 512, JSON_THROW_ON_ERROR);
            $this->configStorage->write($editedConfig);
            $io->writeln('<info>Configuration updated successfully.</info>');
            return Command::SUCCESS;
        } catch (\JsonException $e) {
            $io->writeln('<fg=red>Invalid JSON: ' . $e->getMessage() . '</fg=red>');
            return Command::FAILURE;
        } catch (\Exception $e) {
            $io->writeln('<fg=red>An error occurred: ' . $e->getMessage() . '</fg=red>');
            return Command::FAILURE;
        }
    }

    /**
     * Validate and sanitize editor command to prevent command injection and path traversal.
     *
     * @param string $editor
     * @return string|null Returns validated editor command or null if invalid
     */
    /**
     * Validate editor command to prevent command injection and path traversal.
     *
     * Since we use proc_open() with array syntax, we bypass the shell entirely,
     * so we only need to validate the path itself, not shell metacharacters.
     *
     * @param string $editor
     * @return string|null Returns validated editor path or null if invalid
     */
    private function validateEditorCommand(string $editor): ?string
    {
        // Remove any whitespace
        $editor = trim($editor);

        // Empty string is invalid
        if ($editor === '') {
            return null;
        }

        // Prevent null bytes and invalid path characters
        // Since we use array syntax with proc_open(), shell metacharacters won't execute,
        // but we still reject them as invalid path characters
        if (str_contains($editor, "\0") || str_contains($editor, ';') || str_contains($editor, ' ')) {
            return null;
        }

        // Check if it's a simple executable name (no path separators)
        $isSimpleName = !str_contains($editor, '/') && !str_contains($editor, '\\');

        if ($isSimpleName) {
            // Simple executable name - use basename() to ensure no path components
            // and validate it contains only safe characters using ctype functions
            $commandName = basename($editor);
            if ($commandName === $editor && ctype_alnum(str_replace(['-', '_', '.'], '', $commandName))) {
                return $editor;
            }
            return null;
        }

        // It's a path - must be absolute (start with / on Unix or drive letter on Windows)
        // Relative paths are not allowed for security (prevents directory traversal)
        $isAbsolute = str_starts_with($editor, '/')
            || (PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Z]:/i', $editor));

        if (!$isAbsolute) {
            // Relative paths are not allowed - they could be used for directory traversal
            return null;
        }

        // Resolve path to prevent directory traversal
        // Use realpath() to resolve any .. components and normalize the path
        $resolvedPath = realpath($editor);

        if ($resolvedPath !== false) {
            // Path exists and was resolved - use the resolved path
            // This ensures any .. components are resolved
            $editor = $resolvedPath;
        } else {
            // Path doesn't exist yet (e.g., test editor scripts)
            // Manually resolve .. components to prevent directory traversal
            $normalizedPath = $this->normalizePath($editor);
            if ($normalizedPath === null || str_contains($normalizedPath, '..')) {
                return null;
            }
            $editor = $normalizedPath;
        }

        // Final check: ensure resolved path doesn't contain ..
        if (str_contains($editor, '..')) {
            return null;
        }

        // Prevent protocol-like strings (multiple slashes at start on Unix)
        if (PHP_OS_FAMILY !== 'Windows' && str_starts_with($editor, '//')) {
            return null;
        }

        // Validate path contains only safe characters
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows: allow alphanumeric, hyphens, underscores, dots, backslashes, colons, slashes
            if (!preg_match('/^[a-zA-Z0-9_.\\\\:\/\\-]+$/i', $editor)) {
                return null;
            }
        } else {
            // Unix: allow alphanumeric, hyphens, underscores, dots, slashes
            if (!preg_match('/^[a-zA-Z0-9_.\/\\-]+$/', $editor)) {
                return null;
            }
            // Prevent protocol-like strings (multiple slashes at start)
            if (preg_match('/^\/\/+/', $editor)) {
                return null;
            }
        }

        return $editor;
    }

    /**
     * Normalize an absolute path by resolving .. and . components without requiring the path to exist.
     * This prevents directory traversal attacks.
     *
     * @param string $path Must be an absolute path
     * @return string|null Normalized path or null if invalid
     */
    private function normalizePath(string $path): ?string
    {
        // Normalize separators
        $normalized = str_replace('\\', '/', $path);

        // Extract Windows drive letter if present
        $driveLetter = '';
        if (PHP_OS_FAMILY === 'Windows' && preg_match('/^([A-Z]:)/i', $normalized, $matches)) {
            $driveLetter = $matches[1];
            $normalized = substr($normalized, 2); // Remove drive letter
        }

        // Split by directory separator
        $components = explode('/', $normalized);
        $parts = [];

        foreach ($components as $component) {
            if ($component === '' || $component === '.') {
                // Skip empty components and current directory references
                continue;
            }

            if ($component === '..') {
                // Go up one directory
                if (empty($parts)) {
                    // Can't go above root - this is a traversal attempt
                    return null;
                }
                // Remove last component (go up one level)
                array_pop($parts);
            } else {
                $parts[] = $component;
            }
        }

        // Reconstruct path
        $result = implode('/', $parts);

        // Restore absolute path prefix
        if ($driveLetter !== '') {
            $result = $driveLetter . '/' . $result;
        } else {
            $result = '/' . $result;
        }

        return $result;
    }

    /**
     * Get the editor command to use.
     *
     * @return string
     */
    private function getEditor(): string
    {
        // Try environment variables
        $editor = getenv('VISUAL');
        if ($editor !== false) {
            $validated = $this->validateEditorCommand($editor);
            if ($validated !== null) {
                return $validated;
            }
        }

        $editor = getenv('EDITOR');
        if ($editor !== false) {
            $validated = $this->validateEditorCommand($editor);
            if ($validated !== null) {
                return $validated;
            }
        }

        // Default editors
        if (PHP_OS_FAMILY === 'Windows') {
            return 'notepad';
        }

        // Try common Unix editors
        $editors = ['vim', 'nano', 'vi'];
        foreach ($editors as $ed) {
            $whichCommand = 'which ' . escapeshellarg($ed) . ' 2>/dev/null';
            if (shell_exec($whichCommand) !== null) {
                return $ed;
            }
        }

        return 'vi'; // Fallback
    }

    /**
     * Open content in editor and return edited content.
     *
     * @param string $content
     * @param string $editor
     * @param string|null $filePath Optional file path to edit directly
     * @return string|null
     */
    private function openInEditor(string $content, string $editor, ?string $filePath = null): ?string
    {
        // Validate editor command before use
        $validatedEditor = $this->validateEditorCommand($editor);
        if ($validatedEditor === null) {
            return null;
        }

        // Check if we're running in a test environment
        $isTestEnvironment = $this->isPhpUnitRunning();

        // Check if we're in a non-interactive environment
        $isStdinTty = function_exists('stream_isatty')
            && defined('STDIN')
            && is_resource(STDIN)
            && stream_isatty(STDIN);

        // Check if editor is likely a test script (non-interactive)
        // Test scripts should NEVER be connected to real STDIN, even if STDIN is a TTY
        $isTestScript = str_contains($validatedEditor, 'test')
            || str_starts_with($validatedEditor, sys_get_temp_dir())
            || str_contains($validatedEditor, 'zebra_test_editor');

        // In test environments, only allow test scripts
        if ($isTestEnvironment && !$isTestScript) {
            return null;
        }

        // If STDIN is not a TTY and it's not a test script, don't open interactive editors
        if (!$isStdinTty && !$isTestScript) {
            return null;
        }

        if ($filePath !== null && file_exists($filePath)) {
            // Edit file directly using proc_open with array syntax
            // ALWAYS redirect STDIN for test scripts to prevent hanging, even if STDIN is a TTY
            // After the early return check above, if we're not a test script, STDIN must be a TTY (interactive)
            if ($isTestScript) {
                $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
                $descriptorspec = [
                    0 => ['file', $nullDevice, 'r'], // Redirect STDIN to null device
                    1 => ['pipe', 'w'],              // Capture stdout
                    2 => ['pipe', 'w'],              // Capture stderr
                ];
            } else {
                $descriptorspec = [
                    0 => STDIN,   // stdin
                    1 => STDOUT, // stdout
                    2 => STDERR, // stderr
                ];
            }

            // Environment variables are inherited automatically, so TERM will be available if set
            $process = @proc_open([$validatedEditor, $filePath], $descriptorspec, $pipes);
            if (!is_resource($process)) {
                return null;
            }

            // Close any pipes we're not using
            // For non-interactive scripts, we need to read and close stdout/stderr pipes
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    // For test scripts, read any output to prevent blocking
                    // After the early return check above, if we're not a test script, STDIN must be a TTY (interactive)
                    if ($isTestScript) {
                        stream_set_blocking($pipe, false);
                        // Read any available data (non-blocking)
                        while (!feof($pipe)) {
                            $data = fread($pipe, 8192);
                            if ($data === false || $data === '') {
                                break;
                            }
                        }
                    }
                    fclose($pipe);
                }
            }

            $exitCode = proc_close($process);
            if ($exitCode !== 0) {
                return null;
            }

            return file_get_contents($filePath);
        }

        // Use temp file with .json extension so nano can detect syntax highlighting
        $tmpFile = sys_get_temp_dir() . '/zebra_config_' . uniqid('', true) . '.json';
        file_put_contents($tmpFile, $content);

        // Open editor using proc_open with array syntax
        // ALWAYS redirect STDIN for test scripts to prevent hanging, even if STDIN is a TTY
        // After the early return check above, if we're not a test script, STDIN must be a TTY (interactive)
        if ($isTestScript) {
            $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
            $descriptorspec = [
                0 => ['file', $nullDevice, 'r'], // Redirect STDIN to null device
                1 => ['pipe', 'w'],              // Capture stdout
                2 => ['pipe', 'w'],              // Capture stderr
            ];
        } else {
            $descriptorspec = [
                0 => STDIN,   // stdin
                1 => STDOUT,  // stdout
                2 => STDERR,  // stderr
            ];
        }

        // Environment variables are inherited automatically, so TERM will be available if set
        $process = @proc_open([$validatedEditor, $tmpFile], $descriptorspec, $pipes);
        if (!is_resource($process)) {
            unlink($tmpFile);
            return null;
        }

        // Close any pipes we're not using
        // For non-interactive scripts, we need to read and close stdout/stderr pipes
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                // For test scripts, read any output to prevent blocking
                // After the early return check above, if we're not a test script, STDIN must be a TTY (interactive)
                if ($isTestScript) {
                    stream_set_blocking($pipe, false);
                    // Read any available data (non-blocking)
                    while (!feof($pipe)) {
                        $data = fread($pipe, 8192);
                        if ($data === false || $data === '') {
                            break;
                        }
                    }
                }
                fclose($pipe);
            }
        }

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            unlink($tmpFile);
            return null;
        }

        $editedContent = file_get_contents($tmpFile);
        unlink($tmpFile);

        return $editedContent;
    }

    /**
     * Get the config file path.
     *
     * @return string
     */
    private function getConfigFilePath(): string
    {
        // Use reflection to access protected filePath property
        $reflection = new \ReflectionClass($this->configStorage);
        $property = $reflection->getProperty('filePath');
        $property->setAccessible(true);
        return $property->getValue($this->configStorage);
    }
}

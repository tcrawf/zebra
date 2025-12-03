<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\FileStorage\HomeDirectoryTrait;

class InstallCommand extends Command
{
    use HomeDirectoryTrait;

    protected function configure(): void
    {
        $this
            ->setName('install')
            ->setDescription('Install the zebra phar to a platform-appropriate directory');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Check if running from PHAR - install command can only run from source
        $isRunningFromPhar = \Phar::running(false) !== '';
        if ($isRunningFromPhar) {
            $io->error('The install command can only be run from source, not from a PHAR file.');
            $io->writeln('');
            $io->writeln('To install zebra, please run the install command from the source directory:');
            $io->writeln('  cd /path/to/zebra/source');
            $io->writeln('  php bin/zebra install');
            return Command::FAILURE;
        }

        try {
            // Always rebuild the phar before installing
            $io->info('Building zebra phar...');
            if (!$this->buildPhar($io)) {
                $io->error('Could not build zebra phar. Make sure you have built it with: composer run build');
                return Command::FAILURE;
            }

            // Get the phar after building
            $sourcePhar = $this->getSourcePhar();
            if ($sourcePhar === null) {
                $io->error('Could not locate zebra phar.');
                return Command::FAILURE;
            }

            $installDir = $this->getInstallDirectory();
            $targetPath = $installDir . DIRECTORY_SEPARATOR . 'zebra';

            // Create install directory if it doesn't exist
            if (!is_dir($installDir)) {
                if (!mkdir($installDir, 0755, true) && !is_dir($installDir)) {
                    $io->error(sprintf('Failed to create install directory: %s', $installDir));
                    return Command::FAILURE;
                }
            }

            // Check if we can write to the directory
            if (!is_writable($installDir)) {
                $io->error(sprintf('Install directory is not writable: %s', $installDir));
                return Command::FAILURE;
            }

            // Check if zebra is already installed and inform user
            if (file_exists($targetPath)) {
                $io->info('An existing installation of zebra will be overwritten.');
            }

            // Copy phar to install directory (overwrites existing file)
            if (!copy($sourcePhar, $targetPath)) {
                $io->error(sprintf('Failed to copy phar to: %s', $targetPath));
                return Command::FAILURE;
            }

            // Make executable on Unix systems
            if (PHP_OS_FAMILY !== 'Windows') {
                chmod($targetPath, 0755);
            }

            $io->success(sprintf('Zebra installed successfully to: %s', $targetPath));

            // Check if install directory is in PATH
            if (!$this->isInPath($installDir)) {
                $io->warning(sprintf('The install directory "%s" is not in your PATH.', $installDir));
                $io->writeln('');
                $io->writeln('To use zebra from anywhere, add it to your PATH:');
                $io->writeln('');

                if (PHP_OS_FAMILY === 'Windows') {
                    $io->writeln('  For PowerShell:');
                    $io->writeln(sprintf('    $env:Path += ";%s"', $installDir));
                    $io->writeln('');
                    $io->writeln('  For Command Prompt:');
                    $io->writeln(sprintf('    setx PATH "%%PATH%%;%s"', $installDir));
                } else {
                    $shellConfig = $this->getShellConfigFile();
                    $io->writeln(sprintf('  Add this line to %s:', $shellConfig));
                    $io->writeln(sprintf('    export PATH="$PATH:%s"', $installDir));
                }
                $io->writeln('');
            } else {
                $io->info('Install directory is already in your PATH.');
            }

            // Set up bash autocompletion on Unix systems
            if (PHP_OS_FAMILY !== 'Windows') {
                $this->setupAutocompletion($sourcePhar, $targetPath, $installDir, $io);
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error(sprintf('Installation failed: %s', $e->getMessage()));
            return Command::FAILURE;
        }
    }

    /**
     * Get the source phar file path.
     *
     * @return string|null
     */
    private function getSourcePhar(): ?string
    {
        // Check if running from phar
        $pharPath = \Phar::running(false);
        if ($pharPath !== '') {
            return $pharPath;
        }

        // Try to find build/zebra relative to project root
        // Start from vendor/autoload.php location and go up
        $autoloadPath = $this->findAutoloadPath();
        if ($autoloadPath === null) {
            return null;
        }

        // Go up from vendor/autoload.php to project root
        $projectRoot = dirname($autoloadPath, 2);
        $buildPath = $projectRoot . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'zebra';

        if (file_exists($buildPath)) {
            return $buildPath;
        }

        return null;
    }

    /**
     * Find the composer executable.
     *
     * @return string|null
     */
    private function findComposer(): ?string
    {
        // Check common locations
        $possiblePaths = [
            'composer', // In PATH
            '/usr/local/bin/composer',
            '/usr/bin/composer',
            getcwd() . DIRECTORY_SEPARATOR . 'composer.phar',
        ];

        foreach ($possiblePaths as $path) {
            if ($path === 'composer') {
                // Check if composer is in PATH
                $whichOutput = [];
                $whichReturnCode = 0;
                exec('which composer 2>/dev/null', $whichOutput, $whichReturnCode);
                if ($whichReturnCode === 0 && !empty($whichOutput)) {
                    $foundPath = trim($whichOutput[0]);
                    if ($foundPath !== '' && file_exists($foundPath)) {
                        return $foundPath;
                    }
                }
            } else {
                if (file_exists($path) && is_executable($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * Build the zebra phar file.
     *
     * @param SymfonyStyle $io
     * @return bool True if build was successful, false otherwise
     */
    private function buildPhar(SymfonyStyle $io): bool
    {
        // Don't try to build if we're already running from a phar
        if (\Phar::running(false) !== '') {
            return false;
        }

        // Find project root
        $autoloadPath = $this->findAutoloadPath();
        if ($autoloadPath === null) {
            return false;
        }

        $projectRoot = dirname($autoloadPath, 2);
        $composerJson = $projectRoot . DIRECTORY_SEPARATOR . 'composer.json';

        if (!file_exists($composerJson)) {
            return false;
        }

        // Try to run composer run build
        $originalDir = getcwd();
        if ($originalDir === false) {
            return false;
        }

        try {
            if (!chdir($projectRoot)) {
                return false;
            }

            // Ensure build directory exists
            $buildDir = $projectRoot . DIRECTORY_SEPARATOR . 'build';
            if (!is_dir($buildDir)) {
                if (!mkdir($buildDir, 0755, true) && !is_dir($buildDir)) {
                    $io->error('Could not create build directory.');
                    return false;
                }
            }

            // Find composer executable
            $composerPath = $this->findComposer();
            if ($composerPath === null) {
                $io->error('Could not find composer executable.');
                return false;
            }

            $io->writeln('Running: composer run build');
            $command = escapeshellarg($composerPath) . ' run build 2>&1';
            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);

            // Show output to user
            foreach ($output as $line) {
                $io->writeln($line);
            }

            if ($returnCode !== 0) {
                $io->error('Build failed. Please check the output above.');
                return false;
            }

            // Verify the phar was created
            $buildPath = $projectRoot . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'zebra';
            if (!file_exists($buildPath)) {
                $io->error('Build completed but phar file was not found.');
                return false;
            }

            $io->success('Build completed successfully.');
            return true;
        } finally {
            chdir($originalDir);
        }
    }

    /**
     * Find the vendor/autoload.php path.
     *
     * @return string|null
     */
    private function findAutoloadPath(): ?string
    {
        // Try common locations
        $possiblePaths = [
            __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..'
                . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php',
            dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php',
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Get the install directory for the current platform.
     *
     * @return string
     * @throws \RuntimeException If unable to determine home directory
     */
    private function getInstallDirectory(): string
    {
        $homeDir = $this->getHomeDirectory();

        if (PHP_OS_FAMILY === 'Windows') {
            return $homeDir . DIRECTORY_SEPARATOR . 'bin';
        }

        // Unix (Linux/macOS)
        return $homeDir . DIRECTORY_SEPARATOR . '.local' . DIRECTORY_SEPARATOR . 'bin';
    }


    /**
     * Check if the given directory is in PATH.
     *
     * @param string $directory
     * @return bool
     */
    private function isInPath(string $directory): bool
    {
        $path = getenv('PATH');
        if ($path === false) {
            return false;
        }

        // Normalize directory path for comparison
        $normalizedDir = realpath($directory);
        if ($normalizedDir === false) {
            $normalizedDir = $directory;
        }

        // Split PATH by platform-specific separator
        $separator = PHP_OS_FAMILY === 'Windows' ? ';' : ':';
        $pathDirs = explode($separator, $path);

        foreach ($pathDirs as $pathDir) {
            $normalizedPathDir = realpath($pathDir);
            if ($normalizedPathDir === false) {
                $normalizedPathDir = $pathDir;
            }

            // Compare normalized paths
            if ($normalizedDir === $normalizedPathDir) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the shell config file path for Unix systems.
     *
     * @return string
     */
    private function getShellConfigFile(): string
    {
        $shell = getenv('SHELL');
        if ($shell === false) {
            return '~/.bashrc';
        }

        // Determine config file based on shell
        if (str_contains($shell, 'zsh')) {
            $home = getenv('HOME');
            if ($home !== false && file_exists($home . DIRECTORY_SEPARATOR . '.zshrc')) {
                return '~/.zshrc';
            }
        }

        return '~/.bashrc';
    }

    /**
     * Get the absolute path to the shell config file.
     *
     * @return string
     */
    private function getShellConfigFilePath(): string
    {
        $homeDir = $this->getHomeDirectory();
        $shell = getenv('SHELL');
        $isZsh = $shell !== false && str_contains($shell, 'zsh');

        if ($isZsh && file_exists($homeDir . DIRECTORY_SEPARATOR . '.zshrc')) {
            return $homeDir . DIRECTORY_SEPARATOR . '.zshrc';
        }

        return $homeDir . DIRECTORY_SEPARATOR . '.bashrc';
    }

    /**
     * Set up bash/zsh autocompletion for zebra.
     *
     * @param string $sourceZebra Path to the source zebra executable (used to generate completion)
     * @param string $installedZebra Path to the installed zebra executable (for display in messages)
     * @param string $installDir Directory where zebra is installed
     * @param SymfonyStyle $io
     * @return void
     */
    private function setupAutocompletion(
        string $sourceZebra,
        string $installedZebra,
        string $installDir,
        SymfonyStyle $io
    ): void {
        $homeDir = $this->getHomeDirectory();
        $completionScriptPath = $installDir . DIRECTORY_SEPARATOR . 'zebra_completion.sh';
        $shellConfigPath = $this->getShellConfigFilePath();

        // Generate completion script by executing the source zebra command
        try {
            // Use the source zebra (phar or bin/zebra) to generate the completion script
            $command = escapeshellarg($sourceZebra) . ' completion bash 2>&1';
            $completionScript = shell_exec($command);

            if ($completionScript === null || trim($completionScript) === '') {
                $io->warning('Could not generate completion script. Skipping autocompletion setup.');
                return;
            }

            // Write completion script to file
            if (file_put_contents($completionScriptPath, $completionScript) === false) {
                $io->warning('Could not write completion script. Skipping autocompletion setup.');
                return;
            }

            // Make completion script readable
            chmod($completionScriptPath, 0644);

            // Create display path for shell config (use ~ format)
            $completionScriptDisplay = str_replace($homeDir, '~', $completionScriptPath);

            // Check if already sourced in shell config
            if (
                $this->isCompletionSourced(
                    $shellConfigPath,
                    $completionScriptPath,
                    $completionScriptDisplay
                )
            ) {
                $io->info('Bash autocompletion is already set up.');
                return;
            }

            // Append source line to shell config
            $sourceLine = "\n# Zebra CLI autocompletion\nsource {$completionScriptDisplay}\n";
            if (file_put_contents($shellConfigPath, $sourceLine, FILE_APPEND) === false) {
                $io->warning('Could not update shell config file. You can manually add this line:');
                $io->writeln(sprintf('    source %s', $completionScriptDisplay));
                return;
            }

            $shellConfigDisplay = str_replace($homeDir, '~', $shellConfigPath);
            $io->success(sprintf('Bash autocompletion has been set up in %s', $shellConfigDisplay));
            $io->writeln('Reload your shell configuration with: source ' . $shellConfigDisplay);
        } catch (\Exception $e) {
            $io->warning(sprintf('Could not set up autocompletion: %s', $e->getMessage()));
            $io->writeln('You can manually set it up by running:');
            $completionScriptDisplay = str_replace($homeDir, '~', $completionScriptPath);
            $io->writeln(sprintf('    %s completion bash > %s', $installedZebra, $completionScriptDisplay));
            $io->writeln(sprintf('    echo "source %s" >> %s', $completionScriptDisplay, $shellConfigPath));
        }
    }

    /**
     * Check if the completion script is already sourced in the shell config file.
     *
     * @param string $shellConfigPath Path to shell config file
     * @param string $completionScriptPath Absolute path to completion script
     * @param string $completionScriptDisplay Display path (with ~) to completion script
     * @return bool
     */
    private function isCompletionSourced(
        string $shellConfigPath,
        string $completionScriptPath,
        string $completionScriptDisplay
    ): bool {
        if (!file_exists($shellConfigPath)) {
            return false;
        }

        $content = file_get_contents($shellConfigPath);
        if ($content === false) {
            return false;
        }

        // Escape special regex characters in paths
        $escapedDisplayPath = preg_quote($completionScriptDisplay, '/');
        $escapedAbsolutePath = preg_quote($completionScriptPath, '/');

        // Check for various forms of the source line
        $patterns = [
            // Check for display path (~/.local/bin/zebra_completion.sh)
            '/source\s+' . $escapedDisplayPath . '/',
            // Check for absolute path
            '/source\s+' . $escapedAbsolutePath . '/',
            // Check for any zebra_completion.sh reference
            '/source\s+.*zebra_completion\.sh/',
            // Check for . (dot) sourcing
            '/\.\s+' . $escapedDisplayPath . '/',
            '/\.\s+' . $escapedAbsolutePath . '/',
            '/\.\s+.*zebra_completion\.sh/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }
}

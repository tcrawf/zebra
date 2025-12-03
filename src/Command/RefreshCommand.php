<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Client\ZebraApiException;
use Tcrawf\Zebra\Config\ConfigFileStorageInterface;
use Tcrawf\Zebra\Project\ProjectApiServiceInterface;
use Tcrawf\Zebra\Project\ZebraProjectRepositoryInterface;
use Tcrawf\Zebra\User\UserApiServiceInterface;
use Tcrawf\Zebra\User\UserRepositoryInterface;

class RefreshCommand extends Command
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly ZebraProjectRepositoryInterface $projectRepository,
        private readonly UserApiServiceInterface $userApiService,
        private readonly ProjectApiServiceInterface $projectApiService,
        private readonly ConfigFileStorageInterface $configStorage
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('refresh')
            ->setDescription('Refresh all data (user data, projects, and activities) from Zebra API');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Get current user ID from config
        $userId = $this->getConfigUserId();
        if ($userId === null) {
            $io->error('No user configured. Use "zebra user --init" to set up a user.');
            return Command::FAILURE;
        }

        try {
            // Fetch user data
            $io->writeln('<info>Fetching user data...</info>');
            $userData = $this->userApiService->fetchById($userId);

            // Fetch projects data
            $io->writeln('<info>Fetching projects data...</info>');
            $projectsData = $this->projectApiService->fetchAll();

            // Write data to cache
            $io->writeln('<info>Writing data to cache...</info>');
            $this->userRepository->refreshFromData($userId, $userData);
            $this->projectRepository->refreshFromData($projectsData);

            $projectCount = count($projectsData);
            $io->success("Successfully refreshed user data and {$projectCount} projects");

            return Command::SUCCESS;
        } catch (ZebraApiException $e) {
            // Determine which fetch failed based on error message
            $errorMessage = $e->getMessage();
            if (str_contains($errorMessage, 'user')) {
                $io->error("Failed to fetch user data: {$errorMessage}");
            } elseif (str_contains($errorMessage, 'project')) {
                $io->error("Failed to fetch projects data: {$errorMessage}");
            } else {
                $io->error("Failed to refresh data: {$errorMessage}");
            }
            return Command::FAILURE;
        } catch (\Exception $e) {
            $io->error('An unexpected error occurred: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Get the configured user ID from config.
     *
     * @return int|null
     */
    private function getConfigUserId(): ?int
    {
        $userId = $this->configStorage->get('user.id');
        if ($userId === null) {
            return null;
        }
        // Handle both string and integer values
        if (is_int($userId)) {
            return $userId;
        }
        if (is_string($userId) && ctype_digit($userId)) {
            return (int) $userId;
        }
        return null;
    }
}

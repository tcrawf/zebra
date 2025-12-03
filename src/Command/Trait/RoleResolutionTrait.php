<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command\Trait;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tcrawf\Zebra\Activity\ActivityInterface;
use Tcrawf\Zebra\Config\ConfigFileStorageInterface;
use Tcrawf\Zebra\Frame\FrameRepositoryInterface;
use Tcrawf\Zebra\Role\RoleInterface;
use Tcrawf\Zebra\User\UserRepositoryInterface;

/**
 * Trait for resolving roles by ID or name.
 * Requires UserRepositoryInterface, FrameRepositoryInterface, and ConfigFileStorageInterface dependencies.
 */
trait RoleResolutionTrait
{
    /**
     * Resolve a role by ID or name.
     * If no role is provided, first tries to use the last used role for the activity,
     * then falls back to the default role for the current user.
     *
     * @param InputInterface $input
     * @param SymfonyStyle $io
     * @param ActivityInterface $activity The activity to find the last used role for
     * @return RoleInterface
     */
    protected function resolveRole(InputInterface $input, SymfonyStyle $io, ActivityInterface $activity): RoleInterface
    {
        $roleIdentifier = $input->getOption('role');

        // If no role provided, try to use the last used role for this activity
        if ($roleIdentifier === null) {
            $lastUsedRole = $this->getFrameRepository()->getLastUsedRoleForActivity($activity);
            if ($lastUsedRole !== null) {
                $io->info("Using last role for activity '{$activity->name}': {$lastUsedRole->name}");
                return $lastUsedRole;
            }

            // Fall back to default role
            $role = $this->getUserRepository()->getCurrentUserDefaultRole();
            if ($role === null) {
                // Prompt user to configure default role
                $role = $this->promptForDefaultRole($io);
                $io->info("Using default role: {$role->name}");
            } else {
                $io->info("Using default role for activity '{$activity->name}': {$role->name}");
            }
            return $role;
        }

        $user = $this->getUserRepository()->getCurrentUser();
        if ($user === null) {
            $io->writeln(
                '<fg=red>No user ID found in config. Please run "zebra user --init" to set up a user.</fg=red>'
            );
            throw new \RuntimeException('No current user available');
        }

        // Try to find by ID first
        if (ctype_digit($roleIdentifier)) {
            $roleId = (int) $roleIdentifier;
            foreach ($user->roles as $role) {
                if ($role->id === $roleId) {
                    return $role;
                }
            }
            $io->writeln("<fg=red>Role with ID '{$roleIdentifier}' not found for current user.</fg=red>");
            throw new \RuntimeException("Role with ID '{$roleIdentifier}' not found");
        }

        // Try to find by name
        $matchingRoles = $user->findAllRolesByName($roleIdentifier);

        if (empty($matchingRoles)) {
            // No matching roles found, prompt user to select from all available roles
            $allRoles = $user->roles;
            if (empty($allRoles)) {
                $io->writeln('<fg=red>No roles available for current user.</fg=red>');
                throw new \RuntimeException('No roles available');
            }

            $roleNames = array_map(static fn(RoleInterface $role): string => $role->name, $allRoles);
            $question = new ChoiceQuestion(
                "No role found matching '{$roleIdentifier}'. Please select a role:",
                $roleNames
            );
            $selectedRoleName = $io->askQuestion($question);

            // Find the selected role
            foreach ($allRoles as $role) {
                if ($role->name === $selectedRoleName) {
                    return $role;
                }
            }
        } elseif (count($matchingRoles) === 1) {
            // Single match, return it
            return $matchingRoles[0];
        } else {
            // Multiple matches, ask user to choose
            $roleNames = array_map(static fn(RoleInterface $role): string => $role->name, $matchingRoles);
            $question = new ChoiceQuestion(
                "Multiple roles found matching '{$roleIdentifier}'. Please select one:",
                $roleNames
            );
            $selectedRoleName = $io->askQuestion($question);

            // Find the selected role
            foreach ($matchingRoles as $role) {
                if ($role->name === $selectedRoleName) {
                    return $role;
                }
            }
        }

        throw new \RuntimeException('Failed to resolve role');
    }

    /**
     * Prompt user to select and configure a default role.
     *
     * @param SymfonyStyle $io
     * @return RoleInterface
     */
    protected function promptForDefaultRole(SymfonyStyle $io): RoleInterface
    {
        $user = $this->getUserRepository()->getCurrentUser();
        if ($user === null) {
            $io->writeln(
                '<fg=red>No user ID found in config. Please run "zebra user --init" to set up a user.</fg=red>'
            );
            throw new \RuntimeException('No current user available');
        }

        $allRoles = $user->roles;
        if (empty($allRoles)) {
            $io->writeln('<fg=red>No roles available for current user.</fg=red>');
            throw new \RuntimeException('No roles available');
        }

        $roleNames = array_map(static fn(RoleInterface $role): string => $role->name, $allRoles);
        $question = new ChoiceQuestion(
            'No default role configured. Please select a default role:',
            $roleNames
        );
        $selectedRoleName = $io->askQuestion($question);

        // Find the selected role
        $selectedRole = null;
        foreach ($allRoles as $role) {
            if ($role->name === $selectedRoleName) {
                $selectedRole = $role;
                break;
            }
        }

        if ($selectedRole === null) {
            throw new \RuntimeException('Failed to find selected role');
        }

        // Save to config
        $this->getConfigStorage()->set('user.defaultRole.id', $selectedRole->id);
        $io->info("Default role set to: {$selectedRole->name}");

        return $selectedRole;
    }

    /**
     * Get user repository instance.
     * Must be implemented by classes using this trait.
     *
     * @return UserRepositoryInterface
     */
    abstract protected function getUserRepository(): UserRepositoryInterface;

    /**
     * Get frame repository instance.
     * Must be implemented by classes using this trait.
     *
     * @return FrameRepositoryInterface
     */
    abstract protected function getFrameRepository(): FrameRepositoryInterface;

    /**
     * Get config storage instance.
     * Must be implemented by classes using this trait.
     *
     * @return ConfigFileStorageInterface
     */
    abstract protected function getConfigStorage(): ConfigFileStorageInterface;
}

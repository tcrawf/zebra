<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Command\Trait;

use Tcrawf\Zebra\EntityKey\EntityKeyInterface;
use Tcrawf\Zebra\Frame\FrameInterface;
use Tcrawf\Zebra\Project\ProjectRepositoryInterface;

/**
 * Trait for retrieving project names from frames.
 * Requires ProjectRepositoryInterface dependency.
 */
trait ProjectNameHelperTrait
{
    /**
     * Get project name from frame.
     *
     * @param FrameInterface $frame
     * @return string
     */
    private function getProjectName(FrameInterface $frame): string
    {
        $projectEntityKey = $frame->activity->projectEntityKey;
        $project = $this->getProjectRepository()->get($projectEntityKey);
        if ($project !== null) {
            return $project->name;
        }

        // Fallback behavior can be customized
        return $this->getProjectNameFallback($frame, $projectEntityKey);
    }

    /**
     * Get project name fallback when project is not found.
     * Override to customize behavior (StatusCommand uses activity name, others use "Project {key}").
     *
     * @param FrameInterface $frame
     * @param EntityKeyInterface $projectEntityKey
     * @return string
     */
    protected function getProjectNameFallback(FrameInterface $frame, EntityKeyInterface $projectEntityKey): string
    {
        return "Project {$projectEntityKey->toString()}";
    }

    /**
     * Get project repository instance.
     * Must be implemented by classes using this trait.
     *
     * @return ProjectRepositoryInterface
     */
    abstract protected function getProjectRepository(): ProjectRepositoryInterface;
}

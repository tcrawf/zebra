<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Project;

use Tcrawf\Zebra\Activity\ActivityInterface;
use Tcrawf\Zebra\EntityKey\EntityKeyInterface;

/**
 * Pure data entity for projects.
 * Stores project information and associated activities.
 */
readonly class Project implements ProjectInterface
{
    /**
     * @param EntityKeyInterface $entityKey
     * @param string $name
     * @param string $description
     * @param int $status
     * @param array<ActivityInterface> $activities
     */
    public function __construct(
        public EntityKeyInterface $entityKey,
        public string $name,
        public string $description,
        public int $status,
        /**
         * @var array<ActivityInterface>
         */
        public array $activities = []
    ) {
    }

    public function __toString(): string
    {
        return sprintf(
            'Project(entityKey=%s, name=%s, description=%s, status=%d, activities=%d)',
            $this->entityKey->toString(),
            $this->name,
            $this->description,
            $this->status,
            count($this->activities)
        );
    }
}

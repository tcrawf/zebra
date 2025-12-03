<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Activity;

use Tcrawf\Zebra\EntityKey\EntityKeyInterface;

/**
 * Pure data entity for activities.
 * Stores activity information and reference to its project.
 */
readonly class Activity implements ActivityInterface
{
    /**
     * @param EntityKeyInterface $entityKey
     * @param string $name
     * @param string $description
     * @param EntityKeyInterface $projectEntityKey
     * @param string|null $alias
     */
    public function __construct(
        public EntityKeyInterface $entityKey,
        public string $name,
        public string $description,
        public EntityKeyInterface $projectEntityKey,
        public string|null $alias = null
    ) {
    }

    public function __toString(): string
    {
        return sprintf(
            'Activity(entityKey=%s, name=%s, description=%s, projectEntityKey=%s, alias=%s)',
            $this->entityKey->toString(),
            $this->name,
            $this->description,
            $this->projectEntityKey->toString(),
            $this->alias ?? 'null'
        );
    }
}

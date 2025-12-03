<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Role;

/**
 * Pure data entity for roles.
 * Stores role information from the API.
 */
readonly class Role implements RoleInterface
{
    /**
     * @param int $id
     * @param int|null $parentId
     * @param string $name
     * @param string $fullName
     * @param string $type
     * @param string $status
     */
    public function __construct(
        public int $id,
        public int|null $parentId = null,
        public string $name = '',
        public string $fullName = '',
        public string $type = '',
        public string $status = ''
    ) {
    }

    public function __toString(): string
    {
        return sprintf(
            'Role(id=%d, name=%s, fullName=%s)',
            $this->id,
            $this->name,
            $this->fullName
        );
    }
}

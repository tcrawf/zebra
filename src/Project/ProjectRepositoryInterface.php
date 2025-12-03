<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Project;

/**
 * Interface for project repository.
 * Defines the contract for storing and retrieving projects from both local and Zebra sources.
 * This is the main interface used by the proxy repository that combines local and Zebra projects.
 * Extends both ZebraProjectRepositoryInterface and LocalProjectRepositoryInterface to inherit
 * all read methods from the base interface and CRUD methods from the local interface.
 */
interface ProjectRepositoryInterface extends
    ZebraProjectRepositoryInterface,
    LocalProjectRepositoryInterface
{
}

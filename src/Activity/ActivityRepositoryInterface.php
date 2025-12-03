<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Activity;

/**
 * Interface for activity repository.
 * Defines the contract for retrieving activities from both local and Zebra sources.
 * This is the main interface used by the proxy repository that combines local and Zebra activities.
 * Extends both ZebraActivityRepositoryInterface and LocalActivityRepositoryInterface to inherit
 * all read methods from the base interface and CRUD methods from the local interface.
 */
interface ActivityRepositoryInterface extends
    ZebraActivityRepositoryInterface,
    LocalActivityRepositoryInterface
{
}

<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\EntityKey;

/**
 * Enum representing the source of an entity.
 * Local entities are created locally and use UUID identifiers.
 * Zebra entities are fetched from the Zebra API and use integer identifiers.
 */
enum EntitySource: string
{
    case Local = 'local';
    case Zebra = 'zebra';
}

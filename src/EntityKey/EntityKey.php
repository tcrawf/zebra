<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\EntityKey;

use InvalidArgumentException;
use Tcrawf\Zebra\Uuid\Uuid;
use Tcrawf\Zebra\Uuid\UuidInterface;

/**
 * Data Transfer Object for entity keys.
 * Represents an entity identifier with its source (Local or Zebra).
 * Local entities use UUID identifiers, Zebra entities use integer identifiers.
 */
readonly class EntityKey implements EntityKeyInterface
{
    public UuidInterface|int $id;

    /**
     * @param EntitySource $source The source of the entity (Local or Zebra)
     * @param UuidInterface|int|string $id The identifier. For Local source, must be UuidInterface or valid UUID string.
     *                                     For Zebra source, must be int or string representing an int.
     * @throws InvalidArgumentException If the ID type doesn't match the source type
     */
    public function __construct(
        public EntitySource $source,
        UuidInterface|int|string $id
    ) {
        if ($this->source === EntitySource::Local) {
            // For Local source, ID must be UuidInterface or a valid UUID string
            if ($id instanceof UuidInterface) {
                $this->id = $id;
            } elseif (is_string($id)) {
                // Convert string to UuidInterface
                $this->id = Uuid::fromHex($id);
            } else {
                throw new InvalidArgumentException(
                    'Local source requires UuidInterface or valid UUID string, got: ' . gettype($id)
                );
            }
        } else {
            // For Zebra source, ID must be int or string representing an int
            if (is_int($id)) {
                $this->id = $id;
            } elseif (is_string($id)) {
                // Convert string to int if it represents an integer
                if (!ctype_digit($id) && !(str_starts_with($id, '-') && ctype_digit(substr($id, 1)))) {
                    throw new InvalidArgumentException(
                        "Zebra source requires int or string representing an int, got: {$id}"
                    );
                }
                $this->id = (int) $id;
            } else {
                throw new InvalidArgumentException(
                    'Zebra source requires int or string representing an int, got: ' . gettype($id)
                );
            }
        }
    }

    /**
     * Create an EntityKey for a local entity with a UUID.
     *
     * @param UuidInterface|string $uuid The UUID (UuidInterface or hex string)
     * @return self
     */
    public static function local(UuidInterface|string $uuid): self
    {
        return new self(EntitySource::Local, $uuid);
    }

    /**
     * Create an EntityKey for a Zebra entity with an integer ID.
     *
     * @param int|string $id The integer ID (int or string representing an int)
     * @return self
     */
    public static function zebra(int|string $id): self
    {
        return new self(EntitySource::Zebra, $id);
    }

    /**
     * Get the ID as a string representation.
     * For Local source, returns the UUID hex string.
     * For Zebra source, returns the integer as a string.
     *
     * @return string
     */
    public function toString(): string
    {
        if ($this->id instanceof UuidInterface) {
            return $this->id->getHex();
        }
        return (string) $this->id;
    }

    /**
     * String representation of the entity key.
     *
     * @return string
     */
    public function __toString(): string
    {
        return sprintf(
            'EntityKey(source=%s, id=%s)',
            $this->source->value,
            $this->toString()
        );
    }
}

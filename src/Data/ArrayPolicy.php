<?php

namespace BYanelli\Roma\Data;

use BYanelli\Roma\Data\Sources\Data;
use BYanelli\Roma\Serialization\Destination;

/**
 * Maps every property from the plain array of an ArrayPayload. Use Facades\Mapper::fromArray().
 *
 * @internal
 */
readonly class ArrayPolicy implements SourcePolicy
{
    public function cacheKey(): string
    {
        return self::class;
    }

    public function defaultSource(): Source
    {
        return new Data;
    }

    public function attributesForType(string $typeName): array
    {
        return [];
    }

    public function sourceForType(string $typeName): ?Source
    {
        return null;
    }

    public function allowsSource(Source $source): bool
    {
        return $source instanceof Data;
    }

    /**
     * A plain array has no headers or status.
     */
    public function allowsDestination(Destination $destination): bool
    {
        return false;
    }
}

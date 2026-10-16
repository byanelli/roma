<?php

namespace BYanelli\Roma\Data;

use BYanelli\Roma\Data\Sources\Data;
use BYanelli\Roma\Serialization\Destination;

/**
 * Maps the properties from the plain array of an ArrayInput. Use Facades\Mapper::fromArray().
 *
 * @internal
 */
readonly class ArrayPolicy implements SourcePolicy
{
    public function cacheKey(): string
    {
        return self::class;
    }

    public function inputType(): string
    {
        return ArrayInput::class;
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

    /**
     * A plain array has no headers or status.
     */
    public function allowsDestination(Destination $destination): bool
    {
        return false;
    }
}

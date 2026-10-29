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

    /**
     * The plain array.
     *
     * @param  ArrayInput  $input
     * @return array<array-key, mixed>
     */
    public function mapFromInputValue(object $input): array
    {
        return $input->data;
    }

    public function mapFromInputType(): string
    {
        return 'array';
    }

    public function defaultSource(): Source
    {
        return new Data;
    }

    public function attributesForType(string $typeName): array
    {
        return [];
    }

    public function defaultKey(string $propertyName): string
    {
        return $propertyName;
    }

    public function sourceFor(?Source $declared, ?Destination $destination): ?Source
    {
        return $declared;
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

    /**
     * The operation the caller passed to Mapper::fromArray().
     */
    public function operation(object $input): Operation
    {
        return $input instanceof ArrayInput ? $input->operation : Operation::None;
    }
}

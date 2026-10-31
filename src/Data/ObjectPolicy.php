<?php

namespace BYanelli\Roma\Data;

use BYanelli\Roma\Data\Sources\Data;
use BYanelli\Roma\Serialization\Destination;
use LogicException;

/**
 * Defines a class for validating a constructed object. Use Facades\Mapper::validate(). The
 * object's values are validated together, so any source attribute is allowed and all
 * properties map from one source.
 *
 * @internal
 */
readonly class ObjectPolicy implements SourcePolicy
{
    public function cacheKey(): string
    {
        return self::class;
    }

    /**
     * The input type of Data, the only source.
     */
    public function inputType(): string
    {
        return ArrayInput::class;
    }

    /**
     * @throws LogicException always, since the policy reads no input
     */
    public function mapFromInputValue(object $input): never
    {
        throw new LogicException('ObjectPolicy reads no input.');
    }

    /**
     * A #[MapFromInput] property is not validated, so its callable may take any input.
     */
    public function mapFromInputType(): ?string
    {
        return null;
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

    public function sourceFor(?Source $declared, ?Destination $destination): ?Source
    {
        return new Data;
    }

    public function defaultKey(string $propertyName): string
    {
        return $propertyName;
    }

    public function allowsDestination(Destination $destination): bool
    {
        return true;
    }

    /**
     * The operation is passed to Mapper::validate().
     */
    public function operation(object $input): Operation
    {
        return Operation::None;
    }
}

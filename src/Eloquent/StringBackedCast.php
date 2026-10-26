<?php

namespace BYanelli\Roma\Eloquent;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts a string column to a StringBacked class. A string set on the attribute is stored as
 * given and serialized as stored. Created by AsMappedObject.
 *
 * @internal
 *
 * @template T of StringBacked
 *
 * @implements CastsAttributes<?T, T|string|null>
 */
final class StringBackedCast implements CastsAttributes, SerializesCastableAttributes
{
    // A cached object is written back through set() on save and toArray(), which would replace the stored string with toString().
    public bool $withoutObjectCaching = true;

    /**
     * @param  class-string<T>  $class
     */
    public function __construct(
        private readonly string $class,
        private readonly bool $defaultWhenNull = false,
        private readonly bool $nullWhenInvalid = false,
    ) {}

    /**
     * @return ?T
     *
     * @throws InvalidAttributeValue
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?StringBacked
    {
        if ($value === null) {
            return $this->defaultWhenNull && is_a($this->class, HasDefault::class, true) ? $this->class::default() : null;
        }

        $parsed = is_string($value) || is_int($value) ? $this->class::parse((string) $value) : null;

        if ($parsed === null && ! $this->nullWhenInvalid) {
            throw InvalidAttributeValue::on($model, $key, "it is not a valid {$this->class}");
        }

        return $parsed;
    }

    /**
     * @param  T|string|null  $value
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof $this->class) {
            return $value->toString();
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                InvalidAttributeValue::describeModel($model)."'s {$key} takes a {$this->class}, a string or null, not ".get_debug_type($value).'.'
            );
        }

        if (! $this->nullWhenInvalid && $this->class::parse($value) === null) {
            throw new InvalidArgumentException(
                InvalidAttributeValue::describeModel($model)."'s {$key} takes a valid {$this->class}, and the string isn't one."
            );
        }

        return $value;
    }

    /**
     * Only called for a value that reads as an object, since Laravel serializes null as null. A
     * null column read with defaultWhenNull serializes as null.
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $attributes[$key] ?? null;
    }
}

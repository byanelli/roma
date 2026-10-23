<?php

namespace BYanelli\Roma\Eloquent;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use InvalidArgumentException;

/**
 * Casts an attribute to a class. A StringBacked class is stored in a string column, and any
 * other class in a JSON object column, mapped with Mapper::fromArray() and stored with
 * Profile::storage(), sensitive values included. A null column reads as null, and a stored
 * value that can't be read throws InvalidAttributeValue.
 */
final class AsMappedObject implements Castable
{
    /**
     * A null JSON column reads as the class mapped from an empty array, and stays null on save.
     */
    public const string DEFAULT_WHEN_NULL = 'default-when-null';

    /**
     * A stored value that can't be read reads as null, and a StringBacked attribute accepts
     * a string that doesn't parse.
     */
    public const string NULL_WHEN_INVALID = 'null-when-invalid';

    /**
     * The cast for the class, as in `'settings' => AsMappedObject::of(Settings::class)`.
     *
     * @param  class-string  $class
     */
    public static function of(string $class, bool $defaultWhenNull = false, bool $nullWhenInvalid = false): string
    {
        $options = array_keys(array_filter([
            self::DEFAULT_WHEN_NULL => $defaultWhenNull,
            self::NULL_WHEN_INVALID => $nullWhenInvalid,
        ]));

        return self::class.':'.implode(',', [$class, ...$options]);
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     * @return MappedObjectCast<object>|StringBackedCast<StringBacked>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        $class = array_shift($arguments);

        if (! is_string($class) || ! class_exists($class)) {
            throw new InvalidArgumentException('Roma AsMappedObject needs a class, as in AsMappedObject::of(Settings::class).');
        }

        $unknown = array_diff($arguments, [self::DEFAULT_WHEN_NULL, self::NULL_WHEN_INVALID]);

        if ($unknown !== []) {
            throw new InvalidArgumentException('Roma AsMappedObject has no option '.implode(', ', $unknown).'.');
        }

        $defaultWhenNull = in_array(self::DEFAULT_WHEN_NULL, $arguments, true);
        $nullWhenInvalid = in_array(self::NULL_WHEN_INVALID, $arguments, true);

        if (! is_a($class, StringBacked::class, true)) {
            return new MappedObjectCast($class, $defaultWhenNull, $nullWhenInvalid);
        }

        if ($defaultWhenNull) {
            throw new InvalidArgumentException("Roma AsMappedObject's ".self::DEFAULT_WHEN_NULL." option applies to classes stored as JSON, and {$class} is StringBacked.");
        }

        return new StringBackedCast($class, $nullWhenInvalid);
    }
}

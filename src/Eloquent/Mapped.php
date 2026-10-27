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
final class Mapped implements Castable
{
    /**
     * A null column reads as the class mapped from an empty array, or as default() for a
     * StringBacked class that implements HasDefault, and stays null on save.
     */
    public const string DEFAULT_WHEN_NULL = 'default-when-null';

    /**
     * A stored value that can't be read reads as null, and a StringBacked attribute accepts
     * a string that doesn't parse.
     */
    public const string NULL_WHEN_INVALID = 'null-when-invalid';

    /**
     * The cast for the class, as in `'settings' => Mapped::to(Settings::class)`.
     *
     * @param  class-string  $class
     */
    public static function to(string $class, bool $defaultWhenNull = false, bool $nullWhenInvalid = false): string
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
            throw new InvalidArgumentException('Roma Mapped needs a class, as in Mapped::to(Settings::class).');
        }

        $unknown = array_diff($arguments, [self::DEFAULT_WHEN_NULL, self::NULL_WHEN_INVALID]);

        if ($unknown !== []) {
            throw new InvalidArgumentException('Roma Mapped has no option '.implode(', ', $unknown).'.');
        }

        $defaultWhenNull = in_array(self::DEFAULT_WHEN_NULL, $arguments, true);
        $nullWhenInvalid = in_array(self::NULL_WHEN_INVALID, $arguments, true);

        if (! is_a($class, StringBacked::class, true)) {
            return new MappedObjectCast($class, $defaultWhenNull, $nullWhenInvalid);
        }

        if ($defaultWhenNull && ! is_a($class, HasDefault::class, true)) {
            throw new InvalidArgumentException("Roma Mapped's ".self::DEFAULT_WHEN_NULL.' option needs StringBacked class '.$class.' to implement '.HasDefault::class.'.');
        }

        return new StringBackedCast($class, $defaultWhenNull, $nullWhenInvalid);
    }
}

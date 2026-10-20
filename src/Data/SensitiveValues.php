<?php

namespace BYanelli\Roma\Data;

use BYanelli\Roma\Data\Reflection\ReflectionParamOrProperty;
use BYanelli\Roma\Data\Types\Class_;
use ReflectionClass;
use ReflectionProperty;
use SensitiveParameter;
use SplObjectStorage;

/**
 * The values of #[\SensitiveParameter] parameters, and their redaction from exception messages.
 *
 * @internal
 */
final class SensitiveValues
{
    public const string REDACTED = '[redacted]';

    /**
     * The sensitive values in the mapped data of `$class` and of its nested objects.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    public static function inMapping(Class_ $class, array $data, KeyPath $base = new KeyPath): array
    {
        $values = [];

        foreach ($class->properties as $property) {
            if ($property->source?->isInjected()) {
                continue;
            }

            $path = $base->concat($property->fullKey());
            $value = $path->getFrom($data);

            if ($property->hasAttribute(SensitiveParameter::class)) {
                $values = [...$values, ...self::strings($value)];
            } elseif ($property->type instanceof Class_ && is_array($value)) {
                $values = [...$values, ...self::inMapping($property->type, $data, $path)];
            }
        }

        return $values;
    }

    /**
     * The sensitive values of an object's public properties, including those of nested objects
     * and arrays of them.
     *
     * @param  ?SplObjectStorage<object, null>  $seen
     * @return list<string>
     */
    public static function inObject(object $object, ?SplObjectStorage $seen = null): array
    {
        $seen ??= new SplObjectStorage;

        if ($seen->offsetExists($object)) {
            return [];
        }

        $seen->offsetSet($object);
        $values = [];

        foreach (new ReflectionClass($object)->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || ! $property->isInitialized($object)) {
                continue;
            }

            $value = $property->getValue($object);

            $values = [...$values, ...match (true) {
                new ReflectionParamOrProperty($property, instantiate: [])->isSensitive => self::strings($value),
                is_object($value) => self::inObject($value, $seen),
                is_array($value) => array_merge([], ...array_map(
                    fn (mixed $item) => is_object($item) ? self::inObject($item, $seen) : [],
                    array_values($value),
                )),
                default => [],
            }];
        }

        return $values;
    }

    /**
     * @param  list<string>  $values
     */
    public static function redact(string $text, array $values): string
    {
        if ($values === []) {
            return $text;
        }

        // Longest first, so a value that contains another is replaced whole.
        usort($values, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return str_replace($values, self::REDACTED, $text);
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @param  list<string>  $values
     * @return array<string, list<string>>
     */
    public static function redactErrors(array $errors, array $values): array
    {
        return array_map(
            fn (array $messages) => array_map(fn (string $message) => self::redact($message, $values), $messages),
            $errors,
        );
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return match (true) {
            is_array($value) => array_merge([], ...array_map(self::strings(...), array_values($value))),
            is_object($value) => array_merge([], ...array_map(self::strings(...), array_values(get_object_vars($value)))),
            is_string($value), is_int($value), is_float($value) => (string) $value === '' ? [] : [(string) $value],
            default => [],
        };
    }
}

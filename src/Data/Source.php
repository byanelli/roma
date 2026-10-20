<?php

namespace BYanelli\Roma\Data;

use RuntimeException;

/**
 * A part of the input with its own data, such as the query string of a request. Its key is
 * the prefix of its properties' error keys, and its data is validated on its own, so rules
 * refer to keys relative to it.
 *
 * A subclass declares its input class with `@extends Source<Request>`, and PHPStan then types
 * read()'s `$input` as that class.
 *
 * The default allows `Source` as a type with no template argument.
 *
 * @template I of object = object
 */
abstract readonly class Source
{
    /**
     * The prefix of the error keys of the source's properties, e.g. "query" for "query.page".
     */
    abstract public function getKey(): string;

    /**
     * The class or interface of the input this source reads from. A policy allows the source
     * when its input type is this one or a subtype.
     *
     * @return class-string<I>
     */
    abstract public function inputType(): string;

    /**
     * The raw data: an array keyed by property key, or the property's value when
     * isSingleValue() is true.
     *
     * @param  I  $input
     */
    abstract protected function read(object $input): mixed;

    /**
     * The key a property's value has in read()'s array.
     */
    public function normalizeKey(string $key): string
    {
        return $key;
    }

    /**
     * Whether a dot in a property's key separates nested keys, as in "error.message". A source
     * whose data is a flat list of names, such as headers, returns false and keeps a dot as part
     * of the key.
     */
    public function nestsKeys(): bool
    {
        return true;
    }

    /**
     * Whether read() returns the value of one property, such as a request accessor's,
     * instead of an array of values keyed by property key.
     */
    public function isSingleValue(): bool
    {
        return false;
    }

    /**
     * Whether `$other` reads the same data, so that properties of both share one read and one
     * validator.
     */
    public function isSameAs(Source $other): bool
    {
        return $other::class === static::class && $other->getKey() === $this->getKey();
    }

    /**
     * @throws RuntimeException when the input is not an instance of inputType()
     */
    final public function readFrom(object $input): mixed
    {
        if (! is_a($input, $this->inputType())) {
            throw new RuntimeException(sprintf(
                'Roma source "%s" reads from %s, but the input is %s.',
                $this->getKey(),
                class_basename($this->inputType()),
                class_basename($input),
            ));
        }

        return $this->read($input);
    }
}

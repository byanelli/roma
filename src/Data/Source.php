<?php

namespace BYanelli\Roma\Data;

use RuntimeException;

/**
 * A part of the payload with its own data, such as the query string of a request. Its key is
 * the prefix of its properties' error keys, and its data is validated on its own, so rules
 * refer to keys relative to it.
 */
abstract readonly class Source
{
    /**
     * The prefix of the error keys of the source's properties, e.g. "query" for "query.page".
     */
    abstract public function getKey(): string;

    /**
     * The payload class or interface this source reads from. A policy allows the source when
     * its payload type is this one or a subtype.
     *
     * @return class-string<Payload>
     */
    abstract public function payloadType(): string;

    /**
     * The raw data: an array keyed by property key, or the property's value when
     * isSingleValue() is true. `$payload` is an instance of payloadType().
     */
    abstract protected function read(Payload $payload): mixed;

    /**
     * The key a property's value has in read()'s array.
     */
    public function normalizeKey(string $key): string
    {
        return $key;
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
     * @throws RuntimeException when the payload is not an instance of payloadType()
     */
    final public function readFrom(Payload $payload): mixed
    {
        if (! is_a($payload, $this->payloadType())) {
            throw new RuntimeException(sprintf(
                'Roma source "%s" reads from %s, but the payload is %s.',
                $this->getKey(),
                class_basename($this->payloadType()),
                class_basename($payload),
            ));
        }

        return $this->read($payload);
    }
}

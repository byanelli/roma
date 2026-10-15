<?php

namespace BYanelli\Roma\Data;

use RuntimeException;

/**
 * A source that reads its own data from the payload, such as the query string of a request.
 * Its key is the prefix of its properties' error keys, and its data is validated on its own,
 * so rules refer to keys relative to it.
 */
abstract readonly class RootSource extends Source
{
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
    public function isSameAs(RootSource $other): bool
    {
        return $other::class === static::class && $other->getKeySegments() === $this->getKeySegments();
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

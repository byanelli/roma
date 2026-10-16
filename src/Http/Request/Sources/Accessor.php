<?php

namespace BYanelli\Roma\Http\Request\Sources;

use BYanelli\Roma\Data\Source;
use BYanelli\Roma\Http\Attributes\Accessor as AccessorAttribute;

/**
 * One value read from the request object by an accessor attribute such as #[Ip], under
 * "request.ip".
 */
final readonly class Accessor extends RequestSource
{
    public function __construct(private AccessorAttribute $accessor) {}

    public function getKey(): string
    {
        return 'request.'.$this->accessor->getKey();
    }

    public function isSingleValue(): bool
    {
        return true;
    }

    public function isSameAs(Source $other): bool
    {
        return parent::isSameAs($other)
            && $other instanceof self
            && $other->accessor::class === $this->accessor::class;
    }

    protected function read(object $input): mixed
    {
        return $this->accessor->read($input);
    }
}

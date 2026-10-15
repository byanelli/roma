<?php

namespace BYanelli\Roma\Http\Request\Sources;

use BYanelli\Roma\Data\RootSource;
use BYanelli\Roma\Http\Attributes\Accessor as AccessorAttribute;
use Illuminate\Http\Request;

/**
 * One value read from the request object by an accessor attribute such as #[Ip], under
 * "request.ip".
 */
final readonly class Accessor extends RequestSource
{
    public function __construct(private AccessorAttribute $accessor)
    {
        parent::__construct();
    }

    public function getOwnKey(): string
    {
        return $this->accessor->getKey();
    }

    public function getKeySegments(): array
    {
        return ['request', $this->getOwnKey()];
    }

    public function isSingleValue(): bool
    {
        return true;
    }

    public function isSameAs(RootSource $other): bool
    {
        return parent::isSameAs($other)
            && $other instanceof self
            && $other->accessor::class === $this->accessor::class;
    }

    protected function readRequest(Request $request): mixed
    {
        return $this->accessor->read($request);
    }
}

<?php

namespace BYanelli\Roma\TypeScript;

use BYanelli\Roma\Http\Request\RequestSourcePolicy;
use BYanelli\Roma\Serialization\Destination;

/**
 * The policy a response class's definition is built with for TypeScript. A response is never
 * mapped, so it may have #[Status].
 *
 * @internal
 */
readonly class ResponseDefinitionPolicy extends RequestSourcePolicy
{
    public function cacheKey(): string
    {
        return self::class;
    }

    public function allowsDestination(Destination $destination): bool
    {
        return true;
    }
}

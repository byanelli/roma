<?php

namespace BYanelli\Roma\Http\Enums;

use BYanelli\Roma\Http\Attributes\Accessors\Scheme as SchemeAccessor;

/**
 * The request URI scheme.
 */
enum Scheme: string implements HasRequestSource
{
    case Http = 'http';
    case Https = 'https';

    public static function requestSourceAttribute(): SchemeAccessor
    {
        return new SchemeAccessor;
    }
}

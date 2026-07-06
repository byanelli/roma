<?php

namespace BYanelli\Roma\Request\Enums;

use BYanelli\Roma\Request\Attributes\Accessors\Scheme as SchemeAccessor;

/**
 * The request URI scheme.
 */
enum Scheme: string implements HasRequestSource
{
    case Http = 'http';
    case Https = 'https';

    public static function requestSourceAttributes(): array
    {
        return [new SchemeAccessor];
    }
}

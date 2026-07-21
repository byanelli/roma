<?php

namespace BYanelli\Roma\Request\Enums;

use BYanelli\Roma\Request\Attributes\Accessors\Method as MethodAccessor;

/**
 * HTTP request method. Values match Laravel's uppercase `$request->method()`.
 */
enum Method: string implements HasRequestSource
{
    case Get = 'GET';
    case Post = 'POST';
    case Put = 'PUT';
    case Patch = 'PATCH';
    case Delete = 'DELETE';
    case Head = 'HEAD';
    case Options = 'OPTIONS';
    case Trace = 'TRACE';
    case Connect = 'CONNECT';

    public static function requestSourceAttribute(): MethodAccessor
    {
        return new MethodAccessor;
    }
}

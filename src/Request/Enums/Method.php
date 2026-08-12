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
    // "GET with a body": safe and idempotent, but the query travels in the
    // request content instead of the URI (RFC 10008). Laravel reads that
    // content as the body bag, so #[Body] and the default input source both
    // see it.
    case Query = 'QUERY';
    case Head = 'HEAD';
    case Options = 'OPTIONS';
    case Trace = 'TRACE';
    case Connect = 'CONNECT';

    public static function requestSourceAttribute(): MethodAccessor
    {
        return new MethodAccessor;
    }
}

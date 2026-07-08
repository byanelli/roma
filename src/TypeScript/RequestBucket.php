<?php

namespace BYanelli\Roma\TypeScript;

/**
 * The request interface bucket a property belongs to — Body, Query or
 * Headers — or null when the property is not sent in the JSON payload (a
 * cookie, route parameter, file upload or nested request object).
 */
enum RequestBucket
{
    case Body;
    case Query;
    case Headers;
}

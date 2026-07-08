<?php

namespace BYanelli\Roma\TypeScript;

/**
 * The interface bucket a property belongs to — Body, Query, or Headers — or
 * null when the property is not sent in the JSON payload (a cookie, route
 * parameter, file upload, or nested request object).
 */
enum Bucket
{
    case Body;
    case Query;
    case Headers;
}

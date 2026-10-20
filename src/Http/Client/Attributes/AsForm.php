<?php

namespace BYanelli\Roma\Http\Client\Attributes;

use Attribute;

/**
 * Sends the request's body properties as application/x-www-form-urlencoded instead of JSON.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsForm {}

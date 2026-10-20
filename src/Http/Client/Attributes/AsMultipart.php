<?php

namespace BYanelli\Roma\Http\Client\Attributes;

use Attribute;

/**
 * Sends the request's body properties as multipart/form-data instead of JSON. A request with a
 * file property is sent as multipart without it.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsMultipart {}

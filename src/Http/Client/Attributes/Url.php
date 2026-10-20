<?php

namespace BYanelli\Roma\Http\Client\Attributes;

use Attribute;
use BYanelli\Roma\Serialization\Destination;
use BYanelli\Roma\Serialization\DestinationAttribute;

/**
 * The property whose value is the request's URL, such as a "next page" link. A null value leaves
 * the URL to the class's path. A URL passed to sendRequest() takes precedence.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Url implements DestinationAttribute
{
    public function destination(): Destination
    {
        return Destination::Url;
    }

    public function destinationKey(): ?string
    {
        return null;
    }
}

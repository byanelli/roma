<?php

namespace BYanelli\Roma\Response\Attributes;

use Attribute;
use BYanelli\Roma\Serialization\Destination;
use BYanelli\Roma\Serialization\DestinationAttribute;

/**
 * Marks the property whose value is the HTTP status code of the JSON response.
 * That property is lifted out of the serialized body. Without it, the response
 * defaults to 200.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Status implements DestinationAttribute
{
    public function destination(): Destination
    {
        return Destination::Status;
    }

    public function destinationKey(): ?string
    {
        return null;
    }
}

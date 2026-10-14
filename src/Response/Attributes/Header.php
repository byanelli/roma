<?php

namespace BYanelli\Roma\Response\Attributes;

use Attribute;
use BYanelli\Roma\Data\Attributes\WireKeyAttribute;

/**
 * Marks a property whose value becomes a response header with the given name.
 * That property is lifted out of the serialized body. Mirrors the request-side
 * #[Header], which sources a property from a request header.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Header implements WireKeyAttribute
{
    public function __construct(public string $name) {}

    public function getWireKey(): string
    {
        return $this->name;
    }
}

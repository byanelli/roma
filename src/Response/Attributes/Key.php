<?php

namespace BYanelli\Roma\Response\Attributes;

use Attribute;

/**
 * Remaps a response property to a different key in the serialized body. Without
 * it, a property serializes under its PHP name. Mirrors the request-side source
 * attributes' key argument, which maps an incoming key onto a property.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Key
{
    public function __construct(public string $key) {}
}

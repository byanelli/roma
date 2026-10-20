<?php

namespace BYanelli\Roma\Attributes;

use Attribute;
use BYanelli\Roma\Data\Attributes\ExplicitKeyAttribute;
use BYanelli\Roma\Data\Attributes\WireKeyAttribute;

/**
 * The key a property is read from and written to in place of its name. On a top-level
 * property it is the key within the property's source, e.g. `input.page_num`. A dot nests
 * (`error.message`) unless the source is flat, as headers, cookies and route parameters are.
 * An escaped dot (`created\.at`) is part of the key.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Key implements ExplicitKeyAttribute, WireKeyAttribute
{
    public function __construct(public string $key) {}

    public function getKey(): string
    {
        return $this->key;
    }

    public function getWireKey(): string
    {
        return $this->key;
    }
}

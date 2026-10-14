<?php

namespace BYanelli\Roma\Attributes;

use Attribute;
use BYanelli\Roma\Data\Attributes\ExplicitKeyAttribute;
use BYanelli\Roma\Data\Attributes\WireKeyAttribute;

/**
 * The key a property is read from and written to in place of its name. On a top-level
 * property it is the key within the property's source, e.g. `input.page_num`. The key is one
 * segment and may contain a literal dot.
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

<?php

namespace BYanelli\Roma\Attributes;

use Attribute;

/**
 * The discriminator value of a member of a discriminated union. The default is the
 * member's short class name.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class DiscriminatorValue
{
    public function __construct(public string $value) {}
}

<?php

namespace BYanelli\Roma\Request\Attributes;

use Attribute;
use BYanelli\Roma\Data\Attributes\ExplicitKeyAttribute;
use BYanelli\Roma\Data\Attributes\SourceAttribute;
use BYanelli\Roma\Data\Source;
use BYanelli\Roma\Request\Sources;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
readonly class Body implements ExplicitKeyAttribute, SourceAttribute
{
    public function __construct(public ?string $key = null) {}

    public function getSource(): Source
    {
        return new Sources\Body;
    }

    public function getKey(): ?string
    {
        return $this->key;
    }
}

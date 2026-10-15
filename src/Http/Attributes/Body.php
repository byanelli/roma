<?php

namespace BYanelli\Roma\Http\Attributes;

use Attribute;
use BYanelli\Roma\Data\Attributes\ExplicitKeyAttribute;
use BYanelli\Roma\Data\Attributes\SourceAttribute;
use BYanelli\Roma\Data\RootSource;
use BYanelli\Roma\Http\Request\Sources;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
readonly class Body implements ExplicitKeyAttribute, SourceAttribute
{
    public function __construct(public ?string $key = null) {}

    public function getSource(): RootSource
    {
        return new Sources\Body;
    }

    public function getKey(): ?string
    {
        return $this->key;
    }
}

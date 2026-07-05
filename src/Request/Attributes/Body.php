<?php

namespace BYanelli\Roma\Request\Attributes;

use Attribute;
use BYanelli\Roma\Request\Data\Source;
use BYanelli\Roma\Request\Data\Sources;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
readonly class Body implements SourceAttribute
{
    public function getSource(): Source
    {
        return new Sources\Body;
    }
}

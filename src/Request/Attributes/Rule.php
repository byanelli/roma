<?php

namespace BYanelli\Roma\Request\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Rule implements RulesAttribute
{
    public function __construct(private mixed $rule) {}

    /**
     * @return list<mixed>
     */
    public function getRules(AttributeTarget $target): array
    {
        return [$this->rule];
    }
}

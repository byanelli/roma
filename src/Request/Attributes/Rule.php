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
        // An array is a list of rules and must be spread so each element reaches
        // the validator as its own rule. Wrapping it (e.g. [$this->rule]) instead
        // nests it, and Laravel then treats the first element as a parameterless
        // rule name — so "url:http,https" becomes an unknown "url:http,https" rule
        // rather than the "url" rule with parameters.
        return is_array($this->rule) ? array_values($this->rule) : [$this->rule];
    }
}

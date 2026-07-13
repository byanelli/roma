<?php

namespace BYanelli\Roma\Request\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Rule implements RulesAttribute
{
    /**
     * @var list<mixed>
     */
    private array $rules;

    public function __construct(mixed ...$rules)
    {
        $this->rules = array_values($rules);
    }

    /**
     * @return list<mixed>
     */
    public function getRules(AttributeTarget $target): array
    {
        // Each argument is a rule. An array argument is a list of rules and is
        // spread so every element reaches the validator as its own rule —
        // wrapping it instead nests it, and Laravel then treats the first element
        // as a parameterless rule name (e.g. "url:http,https" becomes an unknown
        // rule rather than "url" with parameters). A closure argument (a
        // first-class-callable reference) is left intact for the mapper to
        // resolve through the container at validation time.
        return array_values(
            collect($this->rules)
                ->flatMap(fn (mixed $rule): array => is_array($rule) ? array_values($rule) : [$rule])
                ->all()
        );
    }
}

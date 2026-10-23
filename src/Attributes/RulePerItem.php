<?php

namespace BYanelli\Roma\Attributes;

use Attribute;
use BYanelli\Roma\Data\Attributes\AttributeTarget;

/**
 * Validation rules for each item of an array property, in the forms #[Rule] accepts. The
 * rules apply at the item's key, such as "emails.1". On a property that isn't an array, the
 * class definition throws.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class RulePerItem
{
    /**
     * @var list<mixed>
     */
    public array $rules;

    public function __construct(mixed ...$rules)
    {
        $this->rules = new Rule(...$rules)->getRules(AttributeTarget::Property);
    }
}

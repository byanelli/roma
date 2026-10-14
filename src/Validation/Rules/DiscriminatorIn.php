<?php

namespace BYanelli\Roma\Validation\Rules;

use Closure;

/**
 * The discriminator of a union object must be one of its values. Like a required member,
 * it is checked only when the object is present.
 */
class DiscriminatorIn extends RequiredWithinObject
{
    /**
     * @param  list<string>  $objectKeySegments
     * @param  list<string>  $values
     */
    public function __construct(array $objectKeySegments, private readonly array $values)
    {
        parent::__construct($objectKeySegments);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($this->containingObject($attribute))) {
            return;
        }

        if (! in_array($value, $this->values, true)) {
            $fail('The :attribute field must be one of: '.implode(', ', $this->values).'.');
        }
    }
}

<?php

namespace BYanelli\Roma\Validation\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The type rule for a value of a constructed object that maps from a string or number, such as a
 * date or an enum: the value must already be an instance of the class.
 */
class NativeInstance implements ValidationRule
{
    /**
     * @param  class-string  $class
     */
    public function __construct(private readonly string $class) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof $this->class) {
            $fail("The :attribute field must be an instance of {$this->class}.");
        }
    }
}

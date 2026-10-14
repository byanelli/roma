<?php

namespace BYanelli\Roma\Validation\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects an array with a non-integer key, for `array<int, T>`.
 */
class IntegerKeys implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_array($value) && array_filter(array_keys($value), is_string(...)) !== []) {
            $fail('The :attribute field must only have integer keys.');
        }
    }
}

<?php

namespace BYanelli\Roma\Validation\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects `[]` for a `non-empty-list` or `non-empty-array` that isn't required. Not implicit, so
 * an absent value passes. A required one gets the `required` rule instead.
 */
class NonEmpty implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === []) {
            $fail('The :attribute field must not be empty.');
        }
    }
}

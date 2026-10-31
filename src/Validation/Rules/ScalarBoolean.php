<?php

namespace BYanelli\Roma\Validation\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The rule for a bool property. Any scalar maps to a bool as Laravel's Request::boolean() reads
 * one, so only null, arrays, objects, NAN and INF fail.
 */
class ScalarBoolean implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_scalar($value) || (is_float($value) && ! is_finite($value))) {
            $fail('validation.boolean')->translate();
        }
    }
}

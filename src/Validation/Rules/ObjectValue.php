<?php

namespace BYanelli\Roma\Validation\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects a non-empty list for an object, such as JSON `["x"]`. An empty array passes, since a
 * JSON `{}` decodes to one. A value that isn't an array is left to the "array" rule.
 */
class ObjectValue implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_array($value) && $value !== [] && array_is_list($value)) {
            $fail('The :attribute field must be an object.');
        }
    }
}

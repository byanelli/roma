<?php

namespace BYanelli\Roma\Validation\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Fails NAN, INF, -INF and a numeric string too large for a float, such as "1e999", which pass
 * Laravel's numeric rule. JSON has no such numbers. `$message` is the translation key of the
 * message, such as "validation.integer" for an int.
 */
class FiniteNumber implements ValidationRule
{
    public function __construct(private readonly string $message = 'validation.numeric') {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_numeric($value) && ! is_finite((float) $value)) {
            $fail($this->message)->translate();
        }
    }
}

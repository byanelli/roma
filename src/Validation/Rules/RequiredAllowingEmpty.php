<?php

namespace BYanelli\Roma\Validation\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An array or object value is required, and may be empty. Unlike Laravel's `present`, it rejects
 * null and has the message of `required`. Implicit, so it runs when the key is absent.
 */
class RequiredAllowingEmpty implements ValidationRule
{
    public bool $implicit = true;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::isMissing($value)) {
            $fail('validation.required')->translate();
        }
    }

    /**
     * Laravel's `array` rule skips a blank string, which would otherwise reach the constructor.
     */
    public static function isMissing(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}

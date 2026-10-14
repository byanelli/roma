<?php

namespace BYanelli\Roma\Validation\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Fails a blank string, which Laravel treats as absent and so skips for
 * rules such as "boolean". Without it, PHP would accept "" for a bool
 * property as false.
 */
class RejectBlankString implements ValidationRule
{
    /**
     * Marks the rule implicit so it runs on a blank string.
     */
    public bool $implicit = true;

    /**
     * @param  string  $message  a translation key, e.g. "validation.boolean"
     */
    public function __construct(private readonly string $message) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && trim($value) === '') {
            $fail($this->message)->translate();
        }
    }
}

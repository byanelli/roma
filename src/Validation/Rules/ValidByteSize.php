<?php

namespace BYanelli\Roma\Validation\Rules;

use BYanelli\Roma\Values\ByteSize;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Implicit, so that a blank string fails too; an absent value passes, and "required" reports
 * it.
 */
class ValidByteSize implements ValidationRule
{
    public bool $implicit = true;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || (is_string($value) && ByteSize::bytesIn($value) !== null)) {
            return;
        }

        $fail('The :attribute field must be a size in bytes, or with a unit such as "10MB" or "512KiB".');
    }
}

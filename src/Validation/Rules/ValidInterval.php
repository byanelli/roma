<?php

namespace BYanelli\Roma\Validation\Rules;

use Closure;
use DateInterval;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Fails a value that coercion couldn't turn into an interval. Implicit, so that a blank string
 * fails too; an absent value passes, and "required" reports it.
 */
class ValidInterval implements ValidationRule
{
    public bool $implicit = true;

    public function __construct(private readonly ?string $unit = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value instanceof DateInterval) {
            return;
        }

        $fail($this->unit !== null
            ? "The :attribute field must be a number of {$this->unit} or a duration such as \"90 seconds\"."
            : 'The :attribute field must be a duration such as "90 seconds".');
    }
}

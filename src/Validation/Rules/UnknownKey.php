<?php

namespace BYanelli\Roma\Validation\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Fails a key that #[RejectUnknownKeys] rejects. Implicit, so that a null or blank value fails
 * too.
 */
class UnknownKey implements ValidationRule
{
    public bool $implicit = true;

    public function __construct(private readonly ?string $message = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->message !== null) {
            $fail($this->message);

            return;
        }

        $fail('validation.prohibited')->translate();
    }
}

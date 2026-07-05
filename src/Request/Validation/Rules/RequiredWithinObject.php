<?php

namespace BYanelli\Roma\Request\Validation\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;

/**
 * A member of a nested object is required, but only when that object is
 * actually present. An absent or explicitly-null object leaves its members
 * unvalidated (the object itself resolves to null); a present object — even
 * an empty one — requires its members.
 *
 * Unlike Laravel's `required_with`, an empty array counts as "present", so
 * `{"address": {}}` still reports its missing required members rather than
 * silently passing.
 */
class RequiredWithinObject implements DataAwareRule, ValidationRule
{
    /**
     * Marks the rule implicit so it runs even when the member is absent.
     */
    public bool $implicit = true;

    /**
     * @var array<array-key, mixed>
     */
    private array $data = [];

    public function __construct(private readonly string $objectKey) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // The containing object is absent or null, so nothing is required.
        if (Arr::get($this->data, $this->objectKey) === null) {
            return;
        }

        if ($value === null || $value === '' || (is_array($value) && count($value) === 0)) {
            $fail('validation.required')->translate();
        }
    }
}

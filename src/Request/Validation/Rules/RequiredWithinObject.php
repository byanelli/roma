<?php

namespace BYanelli\Roma\Request\Validation\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

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

    /**
     * @param  list<string>  $objectKeySegments  Exact key keySegments locating the containing object in the validated data.
     */
    public function __construct(private readonly array $objectKeySegments) {}

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
        if ($this->containingObject() === null) {
            return;
        }

        if ($value === null || $value === '' || (is_array($value) && count($value) === 0)) {
            $fail('validation.required')->translate();
        }
    }

    /**
     * Walk the object's key keySegments with exact-key access so a literal dot in
     * a key segment isn't mistaken for structural nesting.
     */
    private function containingObject(): mixed
    {
        $sub = $this->data;

        foreach ($this->objectKeySegments as $keySegment) {
            if (! is_array($sub) || ! array_key_exists($keySegment, $sub)) {
                return null;
            }

            $sub = $sub[$keySegment];
        }

        return $sub;
    }
}

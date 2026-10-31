<?php

namespace BYanelli\Roma\Validation\Rules;

use BYanelli\Roma\Data\KeyPath;
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
     * @param  KeyPath  $object  the containing object in the validated data, where a '*' segment
     *                           matches the array index in the validated attribute
     * @param  ?string  $nullableKey  the member's key in the object when the member may be null,
     *                                so that a null value passes if the key is present
     */
    public function __construct(
        private readonly KeyPath $object,
        private readonly ?string $nullableKey = null,
    ) {}

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
        $object = $this->containingObject($attribute);

        // The containing object is absent, null, or not an array (which its own
        // "array" rule reports), so nothing is required.
        if (! is_array($object)) {
            return;
        }

        if ($value === null && $this->nullableKey !== null && array_key_exists($this->nullableKey, $object)) {
            return;
        }

        if ($value === null || $value === '' || (is_array($value) && count($value) === 0)) {
            $fail('validation.required')->translate();
        }
    }

    protected function containingObject(string $attribute): mixed
    {
        $object = $this->object->hasWildcard() ? $this->resolveWildcards($attribute) : $this->object;

        return $object?->getFrom($this->data);
    }

    /**
     * The object's path with each '*' replaced by the index it matched in `$attribute`, e.g.
     * ["items", "2"] for ["items", "*"] and "items.2.city".
     */
    private function resolveWildcards(string $attribute): ?KeyPath
    {
        $pattern = implode('\.', array_map(
            fn (string $segment) => $segment === '*' ? '([^.]+)' : preg_quote($segment, '/'),
            $this->object->segments,
        ));

        if (preg_match('/^'.$pattern.'\./', $attribute, $matches) !== 1) {
            return null;
        }

        $index = 1;
        $segments = [];

        foreach ($this->object->segments as $segment) {
            $segments[] = $segment === '*' ? $matches[$index++] : $segment;
        }

        return new KeyPath($segments);
    }
}

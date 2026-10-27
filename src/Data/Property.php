<?php

namespace BYanelli\Roma\Data;

use Closure;

readonly class Property
{
    public bool $isRequired;

    public mixed $default;

    public string $errorKey;

    /**
     * `$source` is null for a property of a nested object, whose key path is relative to the
     * object, so the object's definition is the same wherever it appears. `$wirePath` is where
     * the property appears in the client's data, which differs from `$keyPath` for a header,
     * whose key path is normalized ("x_api_key" for "X-Api-Key"), and for a single-value source.
     *
     * @param  array<int, mixed>  $rules
     * @param  array<int, object>  $rawAttributes
     * @param  list<mixed>  $itemRules  the rules for each item of an array, from #[RulePerItem]
     * @param  bool  $requiredOnCreate  from #[RequiredOnCreate]: required in a Create
     * @param  bool  $optionalOnUpdate  from #[OptionalOnUpdate]: required except in an Update
     * @param  list<Closure(mixed): bool>  $ignoreIf  tests of the raw input value; a match treats the key as absent
     */
    public function __construct(
        public string $name,
        public KeyPath $keyPath,
        public KeyPath $wirePath,
        public Type $type,
        public Role $role,
        mixed $default,
        public ?Source $source,
        public array $rules,
        public bool $nullable = false,
        ?string $errorKey = null,
        public array $rawAttributes = [],
        public bool $isVirtual = false,
        public bool $optional = false,
        public bool $nullTakesDefault = false,
        public array $itemRules = [],
        public bool $requiredOnCreate = false,
        public bool $optionalOnUpdate = false,
        public array $ignoreIf = [],
    ) {
        // A nullable property with no explicit default resolves to null when
        // its key is absent, which in turn makes it optional (not required).
        $this->default = ($nullable && $default instanceof NoDefault) ? null : $default;
        $this->isRequired = ! $optional && $this->default instanceof NoDefault;

        // The client-facing name shown in validation errors. Plain fields reuse
        // their source-prefixed path; attributes (headers, accessors) can supply
        // a friendlier one via ErrorKeyAttribute.
        $this->errorKey = $errorKey ?? $this->fullKey()->toKey();
    }

    /**
     * The source's key followed by the key path, or the key path alone in a nested object.
     */
    public function fullKey(): KeyPath
    {
        return $this->source === null ? $this->keyPath : new KeyPath([$this->source->getKey(), ...$this->keyPath->segments]);
    }

    /**
     * @param  class-string  $class
     */
    public function hasAttribute(string $class): bool
    {
        return $this->attribute($class) !== null;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return ?T
     */
    public function attribute(string $class): ?object
    {
        foreach ($this->rawAttributes as $attribute) {
            if ($attribute instanceof $class) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * Whether the property is required in the operation.
     */
    public function isRequiredFor(Operation $operation): bool
    {
        return $operation->requires($this->isRequired, $this->requiredOnCreate, $this->optionalOnUpdate);
    }
}

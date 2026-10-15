<?php

namespace BYanelli\Roma\Data;

use BYanelli\Roma\Data\Sources\Property as PropertySource;
use Illuminate\Support\Str;
use RuntimeException;

readonly class Property
{
    public bool $isRequired;

    public mixed $default;

    public Source $source;

    public string $errorKey;

    /**
     * @param  array<int, mixed>  $rules
     * @param  list<object>  $rawAttributes
     */
    public function __construct(
        public string $name,
        public string $key,
        public string $wireKey,
        public Type $type,
        public Role $role,
        mixed $default,
        Source $parent,
        public array $rules,
        public bool $nullable = false,
        ?string $errorKey = null,
        public array $rawAttributes = [],
        public bool $isVirtual = false,
        public bool $optional = false,
    ) {
        // A key is one opaque key segment. A literal '.' is fully supported: data
        // access walks key segments and validation rules escape it as "\.". A '*'
        // however is Laravel's array wildcard with no escape hatch, so a literal
        // '*' in a key can never be validated — reject it up front rather than
        // silently mis-map. (Property names can't contain these; the realistic
        // culprit is a header/key attribute given such a name.)
        if (Str::contains($key, '*')) {
            throw new RuntimeException("Roma key \"$key\" (property \"$name\") may not contain '*'.");
        }

        // A nullable property with no explicit default resolves to null when
        // its key is absent, which in turn makes it optional (not required).
        $this->default = ($nullable && $default instanceof NoDefault) ? null : $default;
        $this->isRequired = ! $optional && $this->default instanceof NoDefault;
        $this->source = $parent instanceof RootSource && $parent->isSingleValue()
            ? $parent
            : new PropertySource($parent, $parent->normalizeKey($key));

        // The client-facing name shown in validation errors. Plain fields reuse
        // their source-prefixed path; attributes (headers, accessors) can supply
        // a friendlier one via ErrorKeyAttribute.
        $this->errorKey = $errorKey ?? $this->getFullKey();
    }

    public function getFullKey(): string
    {
        return $this->source->getKey();
    }

    /**
     * The ordered key segments of this property within its root source's data: empty for a
     * single-value source, else the property's key. Each segment is opaque and may contain a
     * literal dot, so data access and rule keys must treat them one segment at a time.
     *
     * @return list<string>
     */
    public function getKeySegments(): array
    {
        $keySegments = [];

        for ($source = $this->source; $source->parent !== null; $source = $source->parent) {
            array_unshift($keySegments, $source->getOwnKey());
        }

        return $keySegments;
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
     * The source at the root of this property's source chain: a RootSource for a property of
     * a top-level class, and Sources\Nested for one of a nested object.
     */
    public function getRootSource(): Source
    {
        $source = $this->source;

        while ($source->parent !== null) {
            $source = $source->parent;
        }

        return $source;
    }
}

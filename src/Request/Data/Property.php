<?php

namespace BYanelli\Roma\Request\Data;

use BYanelli\Roma\Request\Data\Sources\Header;
use BYanelli\Roma\Request\Data\Sources\Property as PropertySource;
use Closure;
use Illuminate\Http\Resources\MissingValue;
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
        public Closure $accessor,
        public array $rules,
        public bool $nullable = false,
        ?string $errorKey = null,
        public array $rawAttributes = [],
        public bool $isVirtual = false,
    ) {
        // A key is one opaque key segment. A literal '.' is fully supported: data
        // access walks key segments and validation rules escape it as "\.". A '*'
        // however is Laravel's array wildcard with no escape hatch, so a literal
        // '*' in a key can never be validated — reject it up front rather than
        // silently mis-map. (Property names can't contain these; the realistic
        // culprit is a header/key attribute given such a name.)
        if (Str::contains($key, '*')) {
            throw new RuntimeException("Roma request key \"$key\" (property \"$name\") may not contain '*'.");
        }

        // A nullable property with no explicit default resolves to null when
        // its key is absent, which in turn makes it optional (not required).
        $this->default = ($nullable && $default instanceof MissingValue) ? null : $default;
        $this->isRequired = $this->default instanceof MissingValue;
        $this->source = new PropertySource($parent, $this->normalizeKey($parent, $key));

        // The client-facing name shown in validation errors. Plain fields reuse
        // their source-prefixed path; attributes (headers, accessors) can supply
        // a friendlier one via ErrorKeyAttribute.
        $this->errorKey = $errorKey ?? $this->getFullKey();
    }

    private function normalizeKey(Source $parent, string $key): string
    {
        return (get_class($parent) == Header::class)
            ? Str::of($key)->camel()->snake()->toString()
            : $key;
    }

    public function getFullKey(): string
    {
        return $this->source->getKey();
    }

    /**
     * The ordered key segments for this property (source-prefix key segments
     * plus this property's own key). Each key segment is opaque and may contain a
     * literal dot; callers doing data access or building rule keys must treat
     * them one key segment at a time.
     *
     * @return list<string>
     */
    public function getKeySegments(): array
    {
        return $this->source->getKeySegments();
    }

    /**
     * @param  class-string  $class
     */
    public function hasAttribute(string $class): bool
    {
        return array_filter($this->rawAttributes, fn ($attribute) => $attribute instanceof $class) !== [];
    }

    /**
     * The root source of this property. Every property's source is a chain of
     * Sources\Property wrappers (one per nested key segment); the location it
     * actually reads from is the source at the root of that chain.
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

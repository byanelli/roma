<?php

namespace BYanelli\Roma\Request\Data;

use BYanelli\Roma\Request\Data\Sources\Header;
use BYanelli\Roma\Request\Data\Sources\Property as PropertySource;
use Closure;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Support\Str;

readonly class Property
{
    public bool $isRequired;

    public mixed $default;

    public Source $source;

    public string $errorKey;

    /**
     * @param  array<int, mixed>  $rules
     */
    public function __construct(
        public string $name,
        public string $key,
        public Type $type,
        public Role $role,
        mixed $default,
        Source $parent,
        public Closure $accessor,
        public array $rules,
        public bool $nullable = false,
        ?string $errorKey = null,
    ) {
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
}

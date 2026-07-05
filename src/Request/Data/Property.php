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

    public Source $source;

    /**
     * @param  array<int, mixed>  $rules
     */
    public function __construct(
        public string $name,
        public string $key,
        public Type $type,
        public Role $role,
        public mixed $default,
        Source $parent,
        public Closure $accessor,
        public array $rules,
    ) {
        $this->isRequired = $default instanceof MissingValue;
        $this->source = new PropertySource($parent, $this->normalizeKey($parent, $key));
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

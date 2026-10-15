<?php

namespace BYanelli\Roma\Data;

abstract readonly class Source
{
    public function __construct(public ?Source $parent = null) {}

    abstract public function getOwnKey(): string;

    /**
     * The lookup key for a property placed directly under this source.
     */
    public function normalizeKey(string $key): string
    {
        return $key;
    }

    public function getKey(): string
    {
        return implode('.', $this->getKeySegments());
    }

    /**
     * The ordered key segments from the root source down to this one. Each
     * segment is an opaque array key and may itself contain a literal dot, so
     * data access must walk the segments rather than split getKey() on '.'.
     *
     * @return list<string>
     */
    public function getKeySegments(): array
    {
        $parentKeySegments = $this->parent?->getKeySegments() ?? [];

        return [...$parentKeySegments, $this->getOwnKey()];
    }
}

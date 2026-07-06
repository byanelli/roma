<?php

namespace BYanelli\Roma\Request\Data;

abstract readonly class Source
{
    public function __construct(public ?Source $parent = null) {}

    abstract public function getOwnKey(): string;

    public function getKey(): string
    {
        $parentKey = $this->parent?->getKey() ?? '';

        return empty($parentKey)
            ? $this->getOwnKey()
            : "$parentKey.{$this->getOwnKey()}";
    }

    /**
     * The ordered key keySegments from the root source down to this one. Each
     * key segment is an opaque array key and may itself contain a literal dot;
     * data access must walk these keySegments rather than splitting getKey() on
     * '.', which would misread a literal dot as structural nesting.
     *
     * @return list<string>
     */
    public function getKeySegments(): array
    {
        $parentKeySegments = $this->parent?->getKeySegments() ?? [];

        return [...$parentKeySegments, $this->getOwnKey()];
    }
}

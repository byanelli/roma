<?php

namespace BYanelli\Roma\Data\Sources;

use BYanelli\Roma\Data\Source;

final readonly class Property extends Source
{
    public function __construct(Source $parent, private string $key)
    {
        parent::__construct($parent);
    }

    public function getOwnKey(): string
    {
        return $this->key;
    }
}

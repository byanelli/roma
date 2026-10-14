<?php

namespace BYanelli\Roma\Data\Sources;

use BYanelli\Roma\Data\Source;

/**
 * The root of a nested object's property sources, so a property's key segments are relative
 * to the object and its definition is the same wherever the object appears.
 *
 * @internal
 */
final readonly class Nested extends Source
{
    public function __construct()
    {
        parent::__construct();
    }

    public function getOwnKey(): string
    {
        return '';
    }

    public function getKeySegments(): array
    {
        return [];
    }
}

<?php

namespace BYanelli\Roma\Data\Types;

use BYanelli\Roma\Data\Type;

/**
 * An array shape such as `array{input: float, output?: float}`. The mapped value is a plain array.
 */
final readonly class Shape extends Type
{
    /**
     * @param  list<ShapeItem>  $items
     * @param  bool  $sealed  false for `array{a: int, ...}`, which keeps undeclared keys
     */
    public function __construct(
        public array $items,
        public bool $sealed = true,
    ) {}
}

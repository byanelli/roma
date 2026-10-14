<?php

namespace BYanelli\Roma\Data\Types;

use BYanelli\Roma\Data\Type;

final readonly class ShapeItem
{
    /**
     * @param  bool  $optional  declared as `key?:`
     */
    public function __construct(
        public string $key,
        public Type $type,
        public bool $optional = false,
        public bool $nullable = false,
    ) {}
}

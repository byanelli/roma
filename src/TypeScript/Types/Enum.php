<?php

namespace BYanelli\Roma\TypeScript\Types;

use BYanelli\Roma\TypeScript\Type;

readonly class Enum extends Type
{
    /**
     * @param  class-string<\UnitEnum>  $class
     */
    public function __construct(public string $class) {}
}

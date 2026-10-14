<?php

namespace BYanelli\Roma\Data\Types;

use BYanelli\Roma\Data\Type;
use UnitEnum;

final readonly class Enum extends Type
{
    /**
     * @param  class-string<UnitEnum>  $class
     */
    public function __construct(public string $class) {}
}

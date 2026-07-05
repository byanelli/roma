<?php

namespace BYanelli\Roma\Request\Data\Types;

use BYanelli\Roma\Request\Data\Type;
use UnitEnum;

final readonly class Enum extends Type
{
    /**
     * @param  class-string<UnitEnum>  $class
     */
    public function __construct(public string $class) {}
}

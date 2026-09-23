<?php

namespace BYanelli\Roma\Request\Data\Types;

use BYanelli\Roma\Request\Data\Type;

final readonly class Array_ extends Type
{
    /**
     * @param  bool  $isList  declared as `list<T>`, so the input keys must be sequential
     */
    public function __construct(public Type $memberType, public bool $isList = false) {}
}

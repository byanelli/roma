<?php

namespace BYanelli\Roma\TypeScript\Types;

use BYanelli\Roma\TypeScript\Type;

readonly class Array_ extends Type
{
    public function __construct(public Type $memberType) {}
}

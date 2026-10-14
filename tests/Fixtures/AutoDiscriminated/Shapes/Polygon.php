<?php

namespace BYanelli\Roma\Tests\Fixtures\AutoDiscriminated\Shapes;

abstract readonly class Polygon implements Shape
{
    public function __construct(public int $sides) {}
}

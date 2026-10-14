<?php

namespace BYanelli\Roma\Tests\Fixtures\AutoDiscriminated\Shapes;

final readonly class Circle implements Shape
{
    public function __construct(public float $radius) {}
}

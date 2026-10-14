<?php

namespace BYanelli\Roma\Tests\Fixtures\AutoDiscriminated\Shapes;

use BYanelli\Roma\Attributes\DiscriminatorValue;

#[DiscriminatorValue('sq')]
final readonly class Square implements Shape
{
    public function __construct(public float $side) {}
}

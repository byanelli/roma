<?php

namespace BYanelli\Roma\Tests\Fixtures\AutoDiscriminated\Shapes;

use BYanelli\Roma\Tests\Fixtures\AutoDiscriminated\Animals\Animal;

final readonly class Drawing
{
    /**
     * @param  list<Shape>  $extras
     */
    public function __construct(
        public Shape $main,
        public Animal $pet,
        public array $extras = [],
    ) {}
}

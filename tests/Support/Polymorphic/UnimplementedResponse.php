<?php

namespace BYanelli\Roma\Tests\Support\Polymorphic;

use BYanelli\Roma\Response\Response;

class UnimplementedResponse extends Response
{
    public function __construct(
        public UnimplementedSource $source,
    ) {}
}

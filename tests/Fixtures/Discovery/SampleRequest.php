<?php

namespace BYanelli\Roma\Tests\Fixtures\Discovery;

use BYanelli\Roma\Http\Request\Injection\Request;

#[Request]
class SampleRequest
{
    public function __construct(
        public string $a,
        public string $b,
    ) {}
}

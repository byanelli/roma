<?php

namespace BYanelli\Roma\Tests\Fixtures\Discovery;

use BYanelli\Roma\Http\Response\Response;

class SampleResponse extends Response
{
    public function __construct(
        public string $message,
    ) {}
}

<?php

namespace BYanelli\Roma\Tests\Fixtures\Discovery;

use BYanelli\Roma\Http\Response\IsResponsable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Responsable;

class ResponsableSample implements Arrayable, Responsable
{
    use IsResponsable;

    public function __construct(
        public string $status = 'ok',
    ) {}
}

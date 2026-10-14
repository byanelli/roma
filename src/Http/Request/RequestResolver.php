<?php

namespace BYanelli\Roma\Http\Request;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;

readonly class RequestResolver implements Contracts\RequestResolver
{
    public function __construct(private Container $container) {}

    public function get(): Request
    {
        return $this->container->make('request');
    }
}

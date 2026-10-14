<?php

namespace BYanelli\Roma\Http\Request\Contracts;

use Illuminate\Http\Request;

interface RequestResolver
{
    public function get(): Request;
}

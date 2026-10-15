<?php

namespace BYanelli\Roma\Http\Request;

use BYanelli\Roma\Data\Payload;
use Illuminate\Http\Request;

readonly class RequestPayload implements Payload
{
    public function __construct(public Request $request) {}
}

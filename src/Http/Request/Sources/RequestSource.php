<?php

namespace BYanelli\Roma\Http\Request\Sources;

use BYanelli\Roma\Data\Source;
use Illuminate\Http\Request;

/**
 * A source that reads part of an HTTP request.
 *
 * @extends Source<Request>
 */
abstract readonly class RequestSource extends Source
{
    public function inputType(): string
    {
        return Request::class;
    }
}

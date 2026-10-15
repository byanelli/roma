<?php

namespace BYanelli\Roma\Http\Request\Sources;

use BYanelli\Roma\Data\Payload;
use BYanelli\Roma\Data\RootSource;
use BYanelli\Roma\Http\Request\RequestPayload;
use Illuminate\Http\Request;

/**
 * A root source that reads part of an HTTP request.
 */
abstract readonly class RequestSource extends RootSource
{
    public function payloadType(): string
    {
        return RequestPayload::class;
    }

    /**
     * @param  RequestPayload  $payload
     */
    protected function read(Payload $payload): mixed
    {
        return $this->readRequest($payload->request);
    }

    abstract protected function readRequest(Request $request): mixed;
}

<?php

namespace BYanelli\Roma\Http\Client\Response;

use Illuminate\Http\Client\Response;
use Throwable;

/**
 * A client response and the exception for it, which a response class may inject.
 *
 * @internal
 */
final readonly class ResponseInput
{
    public function __construct(
        public Response $response,
        public Throwable $exception,
    ) {}
}

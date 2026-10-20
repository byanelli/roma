<?php

namespace BYanelli\Roma\Http\Client\Request;

/**
 * The parts of a client request object, ready for a pending request.
 *
 * @internal
 */
final readonly class OutgoingRequest
{
    /**
     * @param  array<string, mixed>  $query
     * @param  array<array-key, mixed>  $body  multipart parts when the body is sent as multipart
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $pathParameters
     */
    public function __construct(
        public string $url,
        public array $query,
        public array $body,
        public array $headers,
        public array $pathParameters,
    ) {}
}

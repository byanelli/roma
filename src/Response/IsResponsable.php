<?php

namespace BYanelli\Roma\Response;

use BYanelli\Roma\Facades\Roma;
use BYanelli\Roma\Serialization\Serialized;
use Illuminate\Http\JsonResponse;

/**
 * Turns a response object into a JSON response of its array form. Use on a
 * class that implements Illuminate\Contracts\Support\Responsable (and Arrayable).
 */
trait IsResponsable
{
    use IsArrayable;

    public function toResponse(mixed $request): JsonResponse
    {
        $serialized = Roma::serialize($this);

        return new JsonResponse($serialized->body, $this->responseStatus($serialized), $this->responseHeaders($serialized));
    }

    /**
     * The value of the #[Status] property, else 200.
     */
    public function responseStatus(Serialized $serialized): int
    {
        return $serialized->status ?? 200;
    }

    /**
     * The values of the #[Header] properties, keyed by header name.
     *
     * @return array<string, string>
     */
    public function responseHeaders(Serialized $serialized): array
    {
        return $serialized->headers;
    }
}

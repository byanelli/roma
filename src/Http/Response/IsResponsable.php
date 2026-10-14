<?php

namespace BYanelli\Roma\Http\Response;

use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Serialization\IsArrayable;
use BYanelli\Roma\Serialization\Profile;
use BYanelli\Roma\Serialization\Serialized;
use Illuminate\Http\JsonResponse;

/**
 * Turns a response object into a JSON response of its array form. Use on a
 * class that implements Illuminate\Contracts\Support\Responsable (and Arrayable).
 */
trait IsResponsable
{
    use IsArrayable;

    /**
     * The body is the output of the class's own toArray() when it declares one.
     */
    public function toResponse(mixed $request): JsonResponse
    {
        $profile = Profile::response();
        $serialized = Serializer::serialize($this, $profile);

        $body = Serializer::overridesToArray($this)
            ? Serializer::normalize($this->toArray($profile), $profile)
            : $serialized->body;

        return new JsonResponse($body, $this->responseStatus($serialized), $this->responseHeaders($serialized));
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

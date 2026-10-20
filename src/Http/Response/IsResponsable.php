<?php

namespace BYanelli\Roma\Http\Response;

use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Http\Attributes\Status;
use BYanelli\Roma\Serialization\IsArrayable;
use BYanelli\Roma\Serialization\Profile;
use BYanelli\Roma\Serialization\Serialized;
use Illuminate\Http\JsonResponse;
use RuntimeException;

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
     * The value of the #[Status] property, else the class-level #[Status], else 200.
     */
    public function responseStatus(Serialized $serialized): int
    {
        return $serialized->status ?? $this->classStatus() ?? 200;
    }

    private function classStatus(): ?int
    {
        $statuses = Status::ofClass(static::class);

        if ($statuses === []) {
            return null;
        }

        if (count($statuses) > 1 || $statuses[0]->code() === null) {
            throw new RuntimeException(
                'Roma response '.static::class.' is sent with its class-level #[Status], so it needs exactly one, with a code such as 201.'
            );
        }

        return $statuses[0]->code();
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

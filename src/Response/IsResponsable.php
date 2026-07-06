<?php

namespace BYanelli\Roma\Response;

use BYanelli\Roma\Response\Attributes\Header;
use BYanelli\Roma\Response\Attributes\Status;
use Illuminate\Http\JsonResponse;
use ReflectionObject;
use ReflectionProperty;

/**
 * Turns a response object into a JSON response of its array form. Use on a
 * class that implements Illuminate\Contracts\Support\Responsable (and Arrayable).
 */
trait IsResponsable
{
    use IsArrayable;

    public function toResponse(mixed $request): JsonResponse
    {
        return new JsonResponse($this->toArray(), $this->responseStatus(), $this->responseHeaders());
    }

    /**
     * The HTTP status code for the response. Defaults to the value of the
     * property marked #[Status], else 200. Override for a dynamic status.
     */
    protected function responseStatus(): int
    {
        foreach (new ReflectionObject($this)->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getAttributes(Status::class) !== []) {
                return (int) $property->getValue($this);
            }
        }

        return 200;
    }

    /**
     * The response headers, collected from properties marked #[Header] using
     * each attribute's name and the property's value. Override for dynamic
     * headers.
     *
     * @return array<string, string>
     */
    protected function responseHeaders(): array
    {
        $headers = [];

        foreach (new ReflectionObject($this)->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $attributes = $property->getAttributes(Header::class);

            if ($attributes === []) {
                continue;
            }

            $headers[$attributes[0]->newInstance()->name] = (string) $property->getValue($this);
        }

        return $headers;
    }
}

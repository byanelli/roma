<?php

namespace BYanelli\Roma\Response;

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
        return new JsonResponse($this->toArray());
    }
}

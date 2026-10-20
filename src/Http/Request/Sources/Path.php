<?php

namespace BYanelli\Roma\Http\Request\Sources;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;

final readonly class Path extends RequestSource
{
    public function getKey(): string
    {
        return 'path';
    }

    public function nestsKeys(): bool
    {
        return false;
    }

    /**
     * @param  Request  $request
     *                            The bound route's parameters. A request with no route, or with a route that isn't bound
     *                            yet, has none, so a missing parameter is a "required" error.
     * @return array<string, mixed>
     */
    protected function read(object $request): array
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return [];
        }

        try {
            return $route->parameters();
        } catch (\LogicException) {
            return [];
        }
    }
}

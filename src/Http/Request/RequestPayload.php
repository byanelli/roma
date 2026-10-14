<?php

namespace BYanelli\Roma\Http\Request;

use BYanelli\Roma\Data\Payload;
use BYanelli\Roma\Data\Types\Class_;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

readonly class RequestPayload implements Payload
{
    public function __construct(private Request $request) {}

    public function data(Class_ $class): array
    {
        return [
            (new Sources\Input)->getKey() => $this->request->input(),
            (new Sources\Query)->getKey() => $this->request->query->all(),
            (new Sources\Header)->getKey() => collect($this->request->server->getHeaders())
                ->mapWithKeys(fn ($val, $key) => [Str::lower($key) => $val])
                ->all(),
            (new Sources\Body)->getKey() => $this->request->isJson()
                ? $this->request->json()->all()
                : $this->request->request->all(),
            (new Sources\File)->getKey() => $this->request->files->all(),
            (new Sources\RouteParameter)->getKey() => $this->routeParameters(),
            (new Sources\Cookie)->getKey() => $this->request->cookies->all(),
            (new Sources\RequestObject_)->getKey() => $this->accessorValues($class),
        ];
    }

    /**
     * The bound route's parameters, or an empty bucket when no route is bound.
     * A request may have no route resolver (route() returns null) or a route
     * that has not yet been bound (parameters() throws); either way a missing
     * route param should surface as a clean "required" validation error rather
     * than crash the mapping.
     *
     * @return array<string, mixed>
     */
    private function routeParameters(): array
    {
        $route = $this->request->route();

        if (! $route instanceof Route) {
            return [];
        }

        try {
            return $route->parameters();
        } catch (\LogicException) {
            return [];
        }
    }

    /**
     * Values read from the request object by accessor attributes, e.g. #[Ajax].
     *
     * @return array<string, mixed>
     */
    private function accessorValues(Class_ $class): array
    {
        $values = [];

        foreach ($class->properties as $property) {
            if ($property->source->parent instanceof Sources\RequestObject_) {
                $values[$property->source->getOwnKey()] = ($property->accessor)($this->request);
            }
        }

        return $values;
    }
}

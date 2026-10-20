<?php

namespace BYanelli\Roma\Http\Client;

use BYanelli\Roma\Http\Client\Response\ResponseMapper;
use BYanelli\Roma\Serialization\Serializer;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;

/**
 * The default TypedFactory. It resolves Laravel's factory from the container on each call, so
 * an instance the Http facade swaps in is used too.
 */
class TypedFactory implements Contracts\TypedFactory
{
    use Testing\FakesTypedRequests;

    public function __construct(private readonly Container $container) {}

    public function sendRequest(object $request, array $responses = [], ?string $url = null): object
    {
        return $this->newRequest()->sendRequest($request, $responses, $url);
    }

    public function withResponses(string ...$classes): TypedPendingRequest
    {
        return $this->newRequest()->withResponses(...$classes);
    }

    public function newRequest(): TypedPendingRequest
    {
        return $this->wrap($this->factory()->createPendingRequest());
    }

    /**
     * A method that returns a pending request returns a TypedPendingRequest.
     *
     * @param  array<array-key, mixed>  $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        $factory = $this->factory();
        $result = $factory->{$method}(...$parameters);

        return match (true) {
            $result instanceof PendingRequest => $this->wrap($result),
            $result === $factory => $this,
            default => $result,
        };
    }

    protected function factory(): Factory
    {
        return $this->container->make(Factory::class);
    }

    /**
     * @param  PendingRequest<bool>  $pending
     */
    private function wrap(PendingRequest $pending): TypedPendingRequest
    {
        return new TypedPendingRequest($pending, $this->container->make(Serializer::class), $this->container->make(ResponseMapper::class));
    }
}

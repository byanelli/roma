<?php

namespace BYanelli\Roma\Http\Client\Testing;

use BYanelli\Roma\Data\ClassDefinitionBuilder;
use BYanelli\Roma\Data\Property;
use BYanelli\Roma\Data\Role;
use BYanelli\Roma\Http\Attributes\Status;
use BYanelli\Roma\Http\Client\Response\ResponsePolicy;
use BYanelli\Roma\Http\Client\Response\Sources\Body;
use BYanelli\Roma\Http\Client\TypedFactory;
use BYanelli\Roma\Http\Client\TypedPendingRequest;
use BYanelli\Roma\Serialization\Profile;
use BYanelli\Roma\Serialization\Serializer;
use Closure;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Client\ResponseSequence;
use InvalidArgumentException;
use PHPUnit\Framework\Assert as PHPUnit;
use ReflectionFunction;
use ReflectionNamedType;

/**
 * Fakes and assertions that match requests by their request class. Requests sent with
 * sendRequest() carry the request object in a request attribute.
 *
 * @phpstan-require-extends TypedFactory
 */
trait FakesTypedRequests
{
    /**
     * Fakes responses as Http::fake() does, and also by request class: a key that is a class
     * matches request objects of that class. A value may be a response object, which is
     * serialized as the API would send it, or a closure that receives the request object and
     * Laravel's request and returns one. Without stubs, all requests get an empty 200 response.
     *
     * @param  ?array<string, mixed>  $stubs
     */
    public function fake(?array $stubs = null): static
    {
        $factory = $this->factory();

        if ($stubs === null) {
            $factory->fake();

            return $this;
        }

        $byClass = $byUrl = [];

        foreach ($stubs as $key => $value) {
            if (class_exists($key) || interface_exists($key)) {
                $byClass[$key] = $value;
            } else {
                $byUrl[$key] = $this->isResponseObject($value) ? $this->response($value) : $value;
            }
        }

        $factory->fake(function (Request $request, array $options) use ($byClass): mixed {
            $object = $request->attributes()[TypedPendingRequest::REQUEST_ATTRIBUTE] ?? null;

            foreach ($byClass as $class => $value) {
                if ($object instanceof $class) {
                    return $this->toStubResponse($value, $object, $request, $options);
                }
            }

            return null;
        });

        $factory->fake($byUrl);

        return $this;
    }

    /**
     * A fake response with a response object serialized as JSON, for Http::fake() and sequences.
     * The status is `$status`, else the object's #[Status] property, else its class-level
     * #[Status] (the first code of a range), else 200.
     *
     * @param  array<string, string>  $headers
     */
    public function response(object $object, ?int $status = null, array $headers = []): PromiseInterface
    {
        $serialized = $this->container->make(Serializer::class)->serialize($object, Profile::clientResponse());

        // Only the keys the class maps, which leaves out the public properties of an exception's parents.
        $keys = array_map(
            fn (Property $property) => $property->keyPath->segments[0] ?? '',
            array_filter(
                new ClassDefinitionBuilder(new ResponsePolicy)->buildClassDefinition($object::class)->properties,
                fn (Property $property) => $property->source instanceof Body && $property->role !== Role::ValidationOnly,
            ),
        );

        return Factory::response(
            array_intersect_key($serialized->body, array_flip($keys)),
            $status ?? $serialized->status ?? $this->classStatus($object::class),
            [...$serialized->headers, ...$headers],
        );
    }

    /**
     * Asserts that a request of a class was sent. A callback receives the request objects of the
     * class its first parameter is typed with, then Laravel's request and response; a callback
     * typed with Laravel's request is passed to Http::assertSent().
     *
     * @param  class-string|callable  $callback
     */
    public function assertSent(string|callable $callback): void
    {
        if (is_callable($callback) && $this->requestClassOf($callback) === null) {
            $this->factory()->assertSent($callback);

            return;
        }

        PHPUnit::assertTrue($this->matchingSent($callback) !== [], 'An expected '.$this->describe($callback).' request was not sent.');
    }

    /**
     * @param  class-string|callable  $callback
     */
    public function assertNotSent(string|callable $callback): void
    {
        if (is_callable($callback) && $this->requestClassOf($callback) === null) {
            $this->factory()->assertNotSent($callback);

            return;
        }

        PHPUnit::assertSame([], $this->matchingSent($callback), 'An unexpected '.$this->describe($callback).' request was sent.');
    }

    /**
     * The request objects of a class that were sent, in order.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return list<T>
     */
    public function sent(string $class): array
    {
        $sent = [];

        foreach ($this->factory()->recorded() as [$request]) {
            $object = $request->attributes()[TypedPendingRequest::REQUEST_ATTRIBUTE] ?? null;

            if ($object instanceof $class) {
                $sent[] = $object;
            }
        }

        return $sent;
    }

    /**
     * The sent request objects of the class, or of the callback's first parameter's class, that
     * the callback accepts.
     *
     * @param  class-string|callable  $callback
     * @return list<object>
     */
    private function matchingSent(string|callable $callback): array
    {
        if (is_string($callback) && ! is_callable($callback)) {
            return $this->sent($callback);
        }

        $class = $this->requestClassOf($callback) ?? throw new InvalidArgumentException('Roma needs the callback\'s first parameter typed with a request class.');
        $matching = [];

        foreach ($this->factory()->recorded() as [$request, $response]) {
            $object = $request->attributes()[TypedPendingRequest::REQUEST_ATTRIBUTE] ?? null;

            if ($object instanceof $class && $callback($object, $request, $response)) {
                $matching[] = $object;
            }
        }

        return $matching;
    }

    /**
     * The class the callable's first parameter is typed with, unless it is Laravel's request.
     *
     * @return ?class-string
     */
    private function requestClassOf(callable $callback): ?string
    {
        $type = (new ReflectionFunction(Closure::fromCallable($callback))->getParameters()[0] ?? null)?->getType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin() || is_a($type->getName(), Request::class, true)) {
            return null;
        }

        /** @var class-string */
        return $type->getName();
    }

    private function describe(string|callable $callback): string
    {
        return class_basename(is_string($callback) && ! is_callable($callback) ? $callback : ($this->requestClassOf($callback) ?? ''));
    }

    /**
     * @param  array<array-key, mixed>  $options
     */
    private function toStubResponse(mixed $value, object $object, Request $request, array $options): mixed
    {
        return match (true) {
            $value instanceof Closure => $this->toStubResponse(
                $this->requestClassOf($value) !== null ? $value($object, $request) : $value($request, $options),
                $object,
                $request,
                $options,
            ),
            $value instanceof ResponseSequence => $this->toStubResponse($value($request), $object, $request, $options),
            $value === null, $value instanceof PromiseInterface => $value,
            is_array($value) => Factory::response($value),
            is_int($value) => Factory::response(status: $value),
            is_string($value) => Factory::response($value),
            $value instanceof Response => Create::promiseFor($value->toPsrResponse()),
            is_object($value) => $this->response($value),
            default => throw new InvalidArgumentException('Roma cannot fake a response with '.get_debug_type($value).'.'),
        };
    }

    private function isResponseObject(mixed $value): bool
    {
        return is_object($value)
            && ! $value instanceof Closure
            && ! $value instanceof PromiseInterface
            && ! $value instanceof ResponseSequence
            && ! $value instanceof Response;
    }

    /**
     * @param  class-string  $class
     */
    private function classStatus(string $class): int
    {
        $status = Status::ofClass($class)[0] ?? null;

        if ($status?->isDefault()) {
            throw new InvalidArgumentException(
                'Roma cannot tell the status of a fake '.class_basename($class)."'s response, since its #[Status] is 'default'. Pass a status to RomaHttp::response()."
            );
        }

        return match (true) {
            $status === null => 200,
            $status->isRange() => (int) $status->pattern * 100,
            default => (int) $status->code(),
        };
    }
}

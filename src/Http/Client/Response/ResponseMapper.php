<?php

namespace BYanelli\Roma\Http\Client\Response;

use BYanelli\Roma\Data\ClassDefinitionBuilder;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Data\ObjectMapper;
use BYanelli\Roma\Data\SensitiveValues;
use BYanelli\Roma\Http\Client\Exceptions\BodySummary;
use BYanelli\Roma\Http\Client\Exceptions\MalformedResponseException;
use BYanelli\Roma\Http\Client\Exceptions\UnmatchedResponseException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Maps a client response onto the response class for its status, then returns the object, or
 * throws it when it is an exception.
 *
 * @internal
 */
final readonly class ResponseMapper
{
    public function __construct(private ObjectMapper $mapper) {}

    /**
     * @param  ?RequestException  $thrown  what Laravel threw for the response
     *
     * @throws Throwable a matched exception class, Laravel's exception for an unmatched status of
     *                   400 and up, UnmatchedResponseException below 400, or MalformedResponseException
     */
    public function map(ResponseClasses $classes, Response $response, ?RequestException $thrown, object $request): object
    {
        // Laravel doesn't throw for a failed response when a throwIf() condition says not to.
        $original = $thrown ?? ($response->failed() ? $response->toException() : null);
        $match = $classes->match($response->status());

        if ($match === null) {
            throw $original ?? $this->unmatched($classes, $response, $request);
        }

        [$class, $fallback] = $match;

        if ($class === Response::class) {
            return $response;
        }

        $exception = $original ?? ($fallback ? $this->unmatched($classes, $response, $request) : new RequestException($response));
        $policy = new ResponsePolicy;
        $mapping = $this->mapper->mapping($class, new ResponseInput($response, $exception), $policy);

        try {
            $this->mapper->validateMapping($mapping);
        } catch (MappingValidationException $e) {
            // An unexpected error body is reported as it would be without Roma.
            if ($fallback) {
                throw $exception;
            }

            $sensitive = [
                ...SensitiveValues::inObject($request),
                ...SensitiveValues::inMapping(new ClassDefinitionBuilder($policy)->buildClassDefinition($class), $mapping->toArray()),
            ];

            throw new MalformedResponseException(
                $class,
                SensitiveValues::redactErrors($e->errors(), $sensitive),
                $response,
                BodySummary::of($response, $sensitive, is_int(RequestException::$truncateAt) ? RequestException::$truncateAt : false),
                $original,
            );
        }

        $object = $this->mapper->build($mapping);

        if (! is_object($object)) {
            throw new RuntimeException("Roma could not build {$class}.");
        }

        if ($object instanceof Throwable) {
            throw $object;
        }

        return $object;
    }

    private function unmatched(ResponseClasses $classes, Response $response, object $request): UnmatchedResponseException
    {
        return new UnmatchedResponseException($response, $request::class, $classes->describe(), SensitiveValues::inObject($request));
    }
}

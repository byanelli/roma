<?php

namespace BYanelli\Roma\Http\Client\Response;

use BYanelli\Roma\Data\ObjectMapper;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Maps a client response onto the response class for its status.
 *
 * @internal
 */
final readonly class ResponseMapper
{
    public function __construct(private ObjectMapper $mapper) {}

    /**
     * @param  list<class-string>  $classes
     */
    public function map(array $classes, Response $response): object
    {
        $class = $classes[0] ?? throw new RuntimeException('Roma has no response class for the response.');

        if ($class === Response::class) {
            return $response;
        }

        return $this->mapper->map($class, new ResponseInput($response, new RequestException($response)), new ResponsePolicy);
    }
}

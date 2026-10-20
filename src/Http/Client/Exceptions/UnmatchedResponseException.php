<?php

namespace BYanelli\Roma\Http\Client\Exceptions;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

/**
 * A response below 400 whose status no response class goes with. Laravel throws its own
 * RequestException for 400 and up, so this covers the rest. The message never has the URL.
 */
class UnmatchedResponseException extends RequestException
{
    /**
     * @param  class-string  $requestClass
     * @param  string  $expected  the statuses and classes that were declared
     * @param  list<string>  $sensitive  values to redact from the body summary
     */
    public function __construct(
        Response $response,
        public readonly string $requestClass,
        public readonly string $expected,
        private readonly array $sensitive = [],
    ) {
        parent::__construct($response);
    }

    protected function prepareMessage(Response $response): string
    {
        $message = sprintf(
            'Roma has no response class for the HTTP %d response to %s. Response classes: %s.',
            $response->status(),
            class_basename($this->requestClass),
            $this->expected,
        );

        $summary = BodySummary::of($response, $this->sensitive, $this->truncateExceptionsAt ?? static::$truncateAt);

        return $summary === '' ? $message : "{$message}\n{$summary}\n";
    }
}

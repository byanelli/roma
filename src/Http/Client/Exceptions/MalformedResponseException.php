<?php

namespace BYanelli\Roma\Http\Client\Exceptions;

use BYanelli\Roma\Data\MappingValidationException;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * A client response that matched a response class but failed to map onto it. The message has the
 * status, the errors and the start of the body, with sensitive values redacted. It never has the
 * URL, since some APIs put their key in the query string.
 */
class MalformedResponseException extends MappingValidationException
{
    /**
     * @param  class-string  $className
     * @param  array<string, list<string>>  $errors  keyed by source-prefixed key, e.g. "body.result.content"
     * @param  string  $body  the start of the body
     * @param  ?Throwable  $previous  Laravel's exception for the response, if it has one
     */
    public function __construct(
        string $className,
        array $errors,
        protected Response $response,
        protected string $body,
        ?Throwable $previous = null,
    ) {
        parent::__construct($className, $errors, $previous);
    }

    public function response(): Response
    {
        return $this->response;
    }

    public function status(): int
    {
        return $this->response->status();
    }

    /**
     * The start of the body, as in the message.
     */
    public function body(): string
    {
        return $this->body;
    }

    protected function summarize(): string
    {
        return "Roma could not map the HTTP {$this->status()} response onto {$this->className}: {$this->listedErrors()}"
            .($this->body !== '' ? "\nBody: {$this->body}" : '');
    }
}

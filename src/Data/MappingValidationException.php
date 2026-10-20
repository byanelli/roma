<?php

namespace BYanelli\Roma\Data;

use RuntimeException;
use Throwable;

/**
 * Data that failed validation while being mapped onto a class. Laravel renders an uncaught
 * one as a 500; the HTTP request mapper converts it to a ValidationException, which is a 422.
 */
class MappingValidationException extends RuntimeException
{
    /**
     * The number of error keys listed in the message.
     */
    protected const int LISTED_ERRORS = 3;

    /**
     * @param  class-string  $className
     * @param  array<string, list<string>>  $errors  messages keyed by the source-prefixed key, e.g. "data.items.0.code"
     */
    public function __construct(
        protected string $className,
        protected array $errors,
        ?Throwable $previous = null,
    ) {
        parent::__construct($this->summarize(), 0, $previous);
    }

    /**
     * @return class-string
     */
    public function className(): string
    {
        return $this->className;
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    protected function summarize(): string
    {
        return "Roma could not map {$this->className}: ".$this->listedErrors();
    }

    /**
     * The first message of each of the first few error keys, e.g. "data.email: The data.email
     * field is required. (and 2 more)".
     */
    protected function listedErrors(): string
    {
        $listed = array_slice($this->errors, 0, static::LISTED_ERRORS, preserve_keys: true);
        $lines = array_map(
            fn (string $key, array $messages) => "{$key}: ".($messages[0] ?? ''),
            array_keys($listed),
            $listed,
        );
        $more = count($this->errors) - count($listed);

        return implode('; ', $lines).($more > 0 ? " (and {$more} more)" : '');
    }
}

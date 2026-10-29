<?php

namespace BYanelli\Roma\Http\Request\Injection;

use Exception;
use Throwable;

class ContextualBindingException extends Exception
{
    /**
     * @param  string  $attribute  the short name of the attribute on the parameter, e.g. "RequestBody"
     */
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, string $attribute = 'Request')
    {
        $prefix = "Error binding the request using the #[{$attribute}] attribute: ";

        parent::__construct($prefix.$message, $code, $previous);
    }
}

<?php

namespace BYanelli\Roma\Http\Request;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Implemented by a request class that answers a failed validation with its own response,
 * like FormRequest::failedValidation(). It covers a precognitive request's errors and a
 * ValidationException thrown by a guard.
 */
interface HandlesFailedValidation
{
    /**
     * The response sent instead of Laravel's 422. The method may also throw an exception of its own.
     */
    public static function failedValidation(ValidationException $exception): Response|Responsable;
}

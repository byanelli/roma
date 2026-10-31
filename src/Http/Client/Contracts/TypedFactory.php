<?php

namespace BYanelli\Roma\Http\Client\Contracts;

use BYanelli\Roma\Http\Client\TypedPendingRequest;
use Illuminate\Http\Client\Factory;

/**
 * Laravel's HTTP client with typed request and response objects. The default implementation
 * wraps the container's Illuminate\Http\Client\Factory, the instance the Http facade uses, so
 * Http::fake(), preventStrayRequests(), global middleware and events apply. Other methods go to
 * that factory, and configuring methods return a TypedPendingRequest.
 *
 * @method TypedPendingRequest baseUrl(string $url)
 * @method TypedPendingRequest withBody(\Psr\Http\Message\StreamInterface|string $content, string $contentType = 'application/json')
 * @method TypedPendingRequest asJson()
 * @method TypedPendingRequest asForm()
 * @method TypedPendingRequest attach(string|array<array-key, mixed> $name, string|resource $contents = '', string|null $filename = null, array<array-key, mixed> $headers = [])
 * @method TypedPendingRequest asMultipart()
 * @method TypedPendingRequest bodyFormat(string $format)
 * @method TypedPendingRequest withQueryParameters(array<array-key, mixed> $parameters)
 * @method TypedPendingRequest contentType(string $contentType)
 * @method TypedPendingRequest acceptJson()
 * @method TypedPendingRequest accept(string $contentType)
 * @method TypedPendingRequest withHeaders(array<array-key, mixed> $headers)
 * @method TypedPendingRequest withHeader(string $name, mixed $value)
 * @method TypedPendingRequest replaceHeaders(array<array-key, mixed> $headers)
 * @method TypedPendingRequest withBasicAuth(string $username, string $password)
 * @method TypedPendingRequest withDigestAuth(string $username, string $password)
 * @method TypedPendingRequest withNtlmAuth(string $username, string $password)
 * @method TypedPendingRequest withToken(string $token, string $type = 'Bearer')
 * @method TypedPendingRequest withUserAgent(string|bool $userAgent)
 * @method TypedPendingRequest withUrlParameters(array<array-key, mixed> $parameters = [])
 * @method TypedPendingRequest withCookies(array<array-key, mixed> $cookies, string $domain)
 * @method TypedPendingRequest maxRedirects(int $max)
 * @method TypedPendingRequest withoutRedirecting()
 * @method TypedPendingRequest withoutVerifying()
 * @method TypedPendingRequest sink(string|resource $to)
 * @method TypedPendingRequest timeout(int|float $seconds)
 * @method TypedPendingRequest connectTimeout(int|float $seconds)
 * @method TypedPendingRequest retry(array<int, int>|int $times, \Closure|int $sleepMilliseconds = 0, callable|null $when = null, bool $throw = true)
 * @method TypedPendingRequest withOptions(array<array-key, mixed> $options)
 * @method TypedPendingRequest withMiddleware(callable $middleware)
 * @method TypedPendingRequest withRequestMiddleware(callable $middleware)
 * @method TypedPendingRequest withResponseMiddleware(callable $middleware)
 * @method TypedPendingRequest withAttributes(array<array-key, mixed> $attributes)
 * @method TypedPendingRequest beforeSending(callable $callback)
 * @method TypedPendingRequest afterResponse(callable|null $callback)
 * @method TypedPendingRequest throw(callable|null $callback = null)
 * @method TypedPendingRequest throwIf(callable|bool $condition)
 * @method TypedPendingRequest throwUnless(callable|bool $condition)
 * @method TypedPendingRequest truncateExceptionsAt(int $length)
 * @method TypedPendingRequest dontTruncateExceptions()
 *
 * @mixin Factory
 */
interface TypedFactory
{
    /**
     * Sends a request object and maps the response onto one of `$responses`, the request class's
     * #[Responses], or those given to withResponses().
     *
     * @template T of object
     *
     * @param  array<class-string<T>>  $responses
     * @param  ?string  $url  replaces the URL from the request class
     * @return ($responses is non-empty-array ? T : object)
     */
    public function sendRequest(object $request, array $responses = [], ?string $url = null): object;

    /**
     * A pending request whose calls also match these response classes.
     *
     * @param  class-string  ...$classes
     */
    public function withResponses(string ...$classes): TypedPendingRequest;
}

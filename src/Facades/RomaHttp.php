<?php

namespace BYanelli\Roma\Facades;

use BYanelli\Roma\Http\Client\Contracts\TypedFactory;
use BYanelli\Roma\Http\Client\TypedPendingRequest;
use Illuminate\Support\Facades\Facade;

/**
 * @method static ($responses is non-empty-array ? T : object) sendRequest<T of object>(object $request, array<class-string<T>> $responses = [], ?string $url = null)
 * @method static TypedPendingRequest withResponses(class-string ...$classes)
 * @method static TypedPendingRequest newRequest()
 * @method static \BYanelli\Roma\Http\Client\TypedFactory fake(array<string, mixed>|null $stubs = null)
 * @method static \GuzzleHttp\Promise\PromiseInterface response(object $object, int|null $status = null, array<string, string> $headers = [])
 * @method static void assertSent(class-string|callable $callback)
 * @method static void assertNotSent(class-string|callable $callback)
 * @method static list<T> sent<T of object>(class-string<T> $class)
 * @method static void assertNothingSent()
 * @method static void assertSentCount(int $count)
 * @method static \BYanelli\Roma\Http\Client\TypedFactory preventStrayRequests(bool $prevent = true)
 * @method static TypedPendingRequest baseUrl(string $url)
 * @method static TypedPendingRequest withBody(\Psr\Http\Message\StreamInterface|string $content, string $contentType = 'application/json')
 * @method static TypedPendingRequest asJson()
 * @method static TypedPendingRequest asForm()
 * @method static TypedPendingRequest attach(string|array<array-key, mixed> $name, string|resource $contents = '', string|null $filename = null, array<array-key, mixed> $headers = [])
 * @method static TypedPendingRequest asMultipart()
 * @method static TypedPendingRequest bodyFormat(string $format)
 * @method static TypedPendingRequest withQueryParameters(array<array-key, mixed> $parameters)
 * @method static TypedPendingRequest contentType(string $contentType)
 * @method static TypedPendingRequest acceptJson()
 * @method static TypedPendingRequest accept(string $contentType)
 * @method static TypedPendingRequest withHeaders(array<array-key, mixed> $headers)
 * @method static TypedPendingRequest withHeader(string $name, mixed $value)
 * @method static TypedPendingRequest replaceHeaders(array<array-key, mixed> $headers)
 * @method static TypedPendingRequest withBasicAuth(string $username, string $password)
 * @method static TypedPendingRequest withDigestAuth(string $username, string $password)
 * @method static TypedPendingRequest withNtlmAuth(string $username, string $password)
 * @method static TypedPendingRequest withToken(string $token, string $type = 'Bearer')
 * @method static TypedPendingRequest withUserAgent(string|bool $userAgent)
 * @method static TypedPendingRequest withUrlParameters(array<array-key, mixed> $parameters = [])
 * @method static TypedPendingRequest withCookies(array<array-key, mixed> $cookies, string $domain)
 * @method static TypedPendingRequest maxRedirects(int $max)
 * @method static TypedPendingRequest withoutRedirecting()
 * @method static TypedPendingRequest withoutVerifying()
 * @method static TypedPendingRequest sink(string|resource $to)
 * @method static TypedPendingRequest timeout(int|float $seconds)
 * @method static TypedPendingRequest connectTimeout(int|float $seconds)
 * @method static TypedPendingRequest retry(array<int, int>|int $times, \Closure|int $sleepMilliseconds = 0, callable|null $when = null, bool $throw = true)
 * @method static TypedPendingRequest withOptions(array<array-key, mixed> $options)
 * @method static TypedPendingRequest withMiddleware(callable $middleware)
 * @method static TypedPendingRequest withRequestMiddleware(callable $middleware)
 * @method static TypedPendingRequest withResponseMiddleware(callable $middleware)
 * @method static TypedPendingRequest withAttributes(array<array-key, mixed> $attributes)
 * @method static TypedPendingRequest beforeSending(callable $callback)
 * @method static TypedPendingRequest afterResponse(callable|null $callback)
 * @method static TypedPendingRequest throw(callable|null $callback = null)
 * @method static TypedPendingRequest throwIf(callable|bool $condition)
 * @method static TypedPendingRequest throwUnless(callable|bool $condition)
 * @method static TypedPendingRequest truncateExceptionsAt(int $length)
 * @method static TypedPendingRequest dontTruncateExceptions()
 *
 * @see \BYanelli\Roma\Http\Client\TypedFactory
 */
class RomaHttp extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TypedFactory::class;
    }
}

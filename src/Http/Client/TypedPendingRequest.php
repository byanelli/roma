<?php

namespace BYanelli\Roma\Http\Client;

use BYanelli\Roma\Http\Client\Exceptions\MalformedResponseException;
use BYanelli\Roma\Http\Client\Request\RequestDefinition;
use BYanelli\Roma\Http\Client\Response\ResponseClasses;
use BYanelli\Roma\Http\Client\Response\ResponseMapper;
use BYanelli\Roma\Serialization\Profile;
use BYanelli\Roma\Serialization\Serializer;
use GuzzleHttp\Psr7\Query;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Wraps Laravel's PendingRequest, so its configuring methods keep the typed sendRequest().
 * Other calls go to the pending request.
 *
 * @method $this baseUrl(string $url)
 * @method $this withBody(\Psr\Http\Message\StreamInterface|string $content, string $contentType = 'application/json')
 * @method $this asJson()
 * @method $this asForm()
 * @method $this attach(string|array<array-key, mixed> $name, string|resource $contents = '', string|null $filename = null, array<array-key, mixed> $headers = [])
 * @method $this asMultipart()
 * @method $this bodyFormat(string $format)
 * @method $this withQueryParameters(array<array-key, mixed> $parameters)
 * @method $this contentType(string $contentType)
 * @method $this acceptJson()
 * @method $this accept(string $contentType)
 * @method $this withHeaders(array<array-key, mixed> $headers)
 * @method $this withHeader(string $name, mixed $value)
 * @method $this replaceHeaders(array<array-key, mixed> $headers)
 * @method $this withBasicAuth(string $username, string $password)
 * @method $this withDigestAuth(string $username, string $password)
 * @method $this withNtlmAuth(string $username, string $password)
 * @method $this withToken(string $token, string $type = 'Bearer')
 * @method $this withUserAgent(string|bool $userAgent)
 * @method $this withUrlParameters(array<array-key, mixed> $parameters = [])
 * @method $this withCookies(array<array-key, mixed> $cookies, string $domain)
 * @method $this maxRedirects(int $max)
 * @method $this withoutRedirecting()
 * @method $this withoutVerifying()
 * @method $this sink(string|resource $to)
 * @method $this timeout(int|float $seconds)
 * @method $this connectTimeout(int|float $seconds)
 * @method $this retry(array<int, int>|int $times, \Closure|int $sleepMilliseconds = 0, callable|null $when = null, bool $throw = true)
 * @method $this withOptions(array<array-key, mixed> $options)
 * @method $this withMiddleware(callable $middleware)
 * @method $this withRequestMiddleware(callable $middleware)
 * @method $this withResponseMiddleware(callable $middleware)
 * @method $this withAttributes(array<array-key, mixed> $attributes)
 * @method $this beforeSending(callable $callback)
 * @method $this afterResponse(callable|null $callback)
 * @method $this throw(callable|null $callback = null)
 * @method $this throwIf(callable|bool $condition)
 * @method $this throwUnless(callable|bool $condition)
 * @method $this truncateExceptionsAt(int $length)
 * @method $this dontTruncateExceptions()
 *
 * @mixin PendingRequest
 */
class TypedPendingRequest
{
    /**
     * The request attribute that holds the request object, for fakes and assertions.
     */
    public const string REQUEST_ATTRIBUTE = 'roma.request';

    /**
     * @var ?callable
     */
    private $throwCallback = null;

    /**
     * @var list<class-string>
     */
    private array $responses = [];

    /**
     * @param  PendingRequest<bool>  $pending
     */
    public function __construct(
        private readonly PendingRequest $pending,
        private readonly Serializer $serializer,
        private readonly ResponseMapper $mapper,
    ) {}

    /**
     * Adds response classes for the calls made with this request, such as an SDK's fallback
     * exception. A request class's own classes take precedence for a status both declare.
     *
     * @param  class-string  ...$classes
     */
    public function withResponses(string ...$classes): static
    {
        $this->responses = [...$this->responses, ...array_values($classes)];

        return $this;
    }

    /**
     * Sends a request object and maps the response onto the class for its status, from
     * `$responses`, the request class's #[Responses] (its parents' after its own), or
     * withResponses(), in that order of precedence. A matched exception class is thrown, and
     * other classes are returned. An unmatched status of 400 and up rethrows Laravel's
     * RequestException, and one below throws UnmatchedResponseException.
     *
     *
     * @template T of object
     *
     * @param  array<class-string<T>>  $responses
     * @param  ?string  $url  replaces the URL from the request class
     * @return ($responses is non-empty-array ? T : object)
     *
     * @throws MalformedResponseException when the matched class fails to map, unless it is the fallback
     */
    public function sendRequest(object $request, array $responses = [], ?string $url = null): object
    {
        $definition = RequestDefinition::of($request::class);
        $outgoing = $definition->outgoing($request, $this->serializer->serialize($request, Profile::clientRequest()), $url);

        // A clone, so the request's attributes, headers and encoding don't stay on this one.
        $pending = (clone $this->pending)
            ->withAttributes([self::REQUEST_ATTRIBUTE => $request])
            ->withHeaders($outgoing->headers)
            ->withUrlParameters($outgoing->pathParameters)
            ->throw($this->throwCallback);

        [$url, $query] = $this->splitQuery($outgoing->url, $outgoing->query);
        $options = $query === [] ? [] : ['query' => $query];

        if ($definition->hasBody) {
            match ($definition->bodyFormat) {
                'json' => $pending->asJson(),
                'form_params' => $pending->asForm(),
                'multipart' => $pending->asMultipart(),
            };

            $options[$definition->bodyFormat] = $outgoing->body;
        }

        $classes = ResponseClasses::merge([
            'the sendRequest() call' => array_values($responses),
            ...$definition->responses,
            'withResponses()' => $this->responses,
        ]);

        try {
            $response = $pending->send($definition->method->value, $url, $options);
            $thrown = null;
        } catch (RequestException $e) {
            // Thrown by throw(), after Laravel's retries.
            $response = $e->response;
            $thrown = $e;
        }

        if (! $response instanceof Response) {
            throw new RuntimeException('Roma sendRequest() does not support async requests.');
        }

        /** @var T */
        return $this->mapper->map($classes, $response, $thrown, $request);
    }

    /**
     * Guzzle replaces a URL's query string with the `query` option, which the pending request's
     * query parameters also use, so a URL's own query, such as a next-page link's, is moved there.
     * The request's values take precedence.
     *
     * @param  array<string, mixed>  $query
     * @return array{string, array<array-key, mixed>}
     */
    private function splitQuery(string $url, array $query): array
    {
        $string = parse_url($url, PHP_URL_QUERY);

        if (! is_string($string) || $string === '') {
            return [$url, $query];
        }

        return [Str::before($url, '?'), [...Query::parse($string), ...$query]];
    }

    /**
     * Laravel's pending request, without the typed methods.
     *
     * @return PendingRequest<bool>
     */
    public function toPendingRequest(): PendingRequest
    {
        return $this->pending;
    }

    /**
     * Configuring methods return this object instead of the pending request.
     *
     * @param  array<array-key, mixed>  $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        // sendRequest() calls throw() on each request, and passes this callback.
        if ($method === 'throw') {
            $this->throwCallback = $parameters[0] ?? null;
        }

        $result = $this->pending->{$method}(...$parameters);

        return $result === $this->pending ? $this : $result;
    }
}

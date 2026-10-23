<?php

namespace BYanelli\Roma\Http\Client\Response;

use BYanelli\Roma\Data\Source;
use BYanelli\Roma\Data\SourcePolicy;
use BYanelli\Roma\Http\Request\Sources as RequestSources;
use BYanelli\Roma\Serialization\Destination;
use Exception;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Maps a response class from an HTTP client response: properties read from the JSON body by
 * default, #[Header] from the headers and #[Status] from the status code.
 *
 * @internal
 */
final readonly class ResponsePolicy implements SourcePolicy
{
    public function cacheKey(): string
    {
        return self::class;
    }

    public function inputType(): string
    {
        return ResponseInput::class;
    }

    public function defaultSource(): Source
    {
        return new Sources\Body;
    }

    public function attributesForType(string $typeName): array
    {
        return [];
    }

    /**
     * #[Body] and #[Header] give the request's sources, which this policy reads from the
     * response instead.
     */
    public function sourceFor(?Source $declared, ?Destination $destination): ?Source
    {
        return match (true) {
            $declared instanceof RequestSources\Body => new Sources\Body,
            $declared instanceof RequestSources\Header => new Sources\Header,
            $declared === null && $destination === Destination::Status => new Sources\Status,
            default => $declared,
        };
    }

    public function sourceForType(string $typeName): ?Source
    {
        return match ($typeName) {
            Response::class => new Sources\RawResponse,
            Throwable::class, Exception::class, RequestException::class => new Sources\Exception,
            default => null,
        };
    }

    public function defaultKey(string $propertyName): string
    {
        return $propertyName;
    }

    public function allowsDestination(Destination $destination): bool
    {
        return in_array($destination, [Destination::Body, Destination::Header, Destination::Status], true);
    }

    public function isCreate(object $input): bool
    {
        return false;
    }
}

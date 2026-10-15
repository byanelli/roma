<?php

namespace BYanelli\Roma\Http\Request;

use BYanelli\Roma\Data\Source;
use BYanelli\Roma\Data\SourcePolicy;
use BYanelli\Roma\Http\Enums\HasRequestSource;
use BYanelli\Roma\Serialization\Destination;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

readonly class RequestSourcePolicy implements SourcePolicy
{
    public function cacheKey(): string
    {
        return self::class;
    }

    public function payloadType(): string
    {
        return RequestPayload::class;
    }

    public function defaultSource(): Source
    {
        return new Sources\Input;
    }

    /**
     * A request-metadata type (an enum like Method, or a value object like
     * Authorization) brings the source attribute it maps from.
     */
    public function attributesForType(string $typeName): array
    {
        return (enum_exists($typeName) || class_exists($typeName)) && is_a($typeName, HasRequestSource::class, true)
            ? [$typeName::requestSourceAttribute()]
            : [];
    }

    public function sourceForType(string $typeName): ?Source
    {
        return in_array($typeName, [UploadedFile::class, SymfonyUploadedFile::class], true)
            ? new Sources\File
            : null;
    }

    /**
     * A #[Header] property maps from a request header. A request has no status.
     */
    public function allowsDestination(Destination $destination): bool
    {
        return $destination === Destination::Header;
    }
}

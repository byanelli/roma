<?php

namespace BYanelli\Roma\Http\Request;

use BYanelli\Roma\Data\Operation;
use BYanelli\Roma\Data\Source;
use BYanelli\Roma\Data\SourcePolicy;
use BYanelli\Roma\Http\Enums\HasRequestSource;
use BYanelli\Roma\Serialization\Destination;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

readonly class RequestSourcePolicy implements SourcePolicy
{
    public function cacheKey(): string
    {
        return self::class;
    }

    public function inputType(): string
    {
        return Request::class;
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

    public function defaultKey(string $propertyName): string
    {
        return $propertyName;
    }

    public function sourceFor(?Source $declared, ?Destination $destination): ?Source
    {
        return $declared;
    }

    public function sourceForType(string $typeName): ?Source
    {
        return in_array($typeName, [UploadedFile::class, SymfonyUploadedFile::class], true)
            ? new Sources\File
            : null;
    }

    /**
     * #[Header], #[Query], #[Path] and #[Body] are sources when mapping a request. A request has
     * no status or URL property.
     */
    public function allowsDestination(Destination $destination): bool
    {
        return in_array($destination, [Destination::Header, Destination::Query, Destination::Path, Destination::Body], true);
    }

    /**
     * POST is a Create, and PUT and PATCH are an Update, as Request::isMethod() reports the
     * method, so a form's _method override counts.
     */
    public function operation(object $input): Operation
    {
        return match (true) {
            ! $input instanceof Request => Operation::None,
            $input->isMethod('POST') => Operation::Create,
            $input->isMethod('PUT'), $input->isMethod('PATCH') => Operation::Update,
            default => Operation::None,
        };
    }
}

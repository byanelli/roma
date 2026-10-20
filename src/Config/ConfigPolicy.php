<?php

namespace BYanelli\Roma\Config;

use BYanelli\Roma\Data\Source;
use BYanelli\Roma\Data\SourcePolicy;
use BYanelli\Roma\Serialization\Destination;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;

/**
 * Maps a config object from the config repository. Properties read from under the prefix.
 *
 * @internal
 */
final readonly class ConfigPolicy implements SourcePolicy
{
    public function __construct(private string $prefix) {}

    /**
     * Definitions differ by prefix, since the prefix is the source's key.
     */
    public function cacheKey(): string
    {
        return self::class.':'.$this->prefix;
    }

    public function inputType(): string
    {
        return Repository::class;
    }

    public function defaultSource(): Source
    {
        return new ConfigSource($this->prefix);
    }

    /**
     * Config keys are snake_case by convention: `$apiKey` reads "api_key".
     */
    public function defaultKey(string $propertyName): string
    {
        return Str::snake($propertyName);
    }

    public function attributesForType(string $typeName): array
    {
        return [];
    }

    public function sourceForType(string $typeName): ?Source
    {
        return null;
    }

    public function allowsDestination(Destination $destination): bool
    {
        return false;
    }
}

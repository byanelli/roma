<?php

namespace BYanelli\Roma\Serialization;

/**
 * An object's serialized properties, grouped by destination.
 */
final readonly class Serialized
{
    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $path
     */
    public function __construct(
        public array $body,
        public array $headers = [],
        public ?int $status = null,
        public array $query = [],
        public array $path = [],
        public ?string $url = null,
    ) {}
}

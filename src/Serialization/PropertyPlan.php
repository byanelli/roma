<?php

namespace BYanelli\Roma\Serialization;

use ReflectionProperty;

/**
 * How one property is serialized, independent of the profile.
 *
 * @internal
 */
final readonly class PropertyPlan
{
    /**
     * @param  string  $key  the key at the destination
     * @param  ?string  $dateFormat  from #[DateFormat]
     */
    public function __construct(
        public ReflectionProperty $reflection,
        public string $key,
        public Destination $destination,
        public ?string $dateFormat,
        public bool $optional,
    ) {}
}

<?php

namespace BYanelli\Roma\Serialization;

use BYanelli\Roma\Data\Type;
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
     * @param  ?Type  $discriminated  where the value, or an element of it, is written with its discriminator
     */
    public function __construct(
        public ReflectionProperty $reflection,
        public string $key,
        public Destination $destination,
        public ?string $dateFormat,
        public bool $optional,
        public ?Type $discriminated = null,
    ) {}
}

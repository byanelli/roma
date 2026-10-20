<?php

namespace BYanelli\Roma\Serialization;

use BYanelli\Roma\Data\Attributes\AttributesCollection;
use BYanelli\Roma\Data\KeyPath;
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
     * @param  KeyPath  $path  the key at the destination, split on dots when the property's source nests them
     * @param  ?string  $dateFormat  from #[DateFormat]
     * @param  ?Type  $discriminated  where the value, or an element of it, is written with its discriminator
     */
    public function __construct(
        public ReflectionProperty $reflection,
        public AttributesCollection $attributes,
        public KeyPath $path,
        public Destination $destination,
        public ?string $dateFormat,
        public bool $optional,
        public ?Type $discriminated = null,
    ) {}
}

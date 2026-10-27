<?php

namespace BYanelli\Roma\Serialization;

use BYanelli\Roma\Data\Attributes\AttributesCollection;
use BYanelli\Roma\Data\KeyPath;
use BYanelli\Roma\Data\Operation;
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
     * @param  bool  $sensitive  whether its constructor parameter has #[\SensitiveParameter]
     * @param  bool  $fallsBackToBody  whether it is written to the body under a profile that doesn't allow its destination
     * @param  bool  $required  whether it is required in any operation, as when mapping
     * @param  bool  $requiredOnCreate  from #[RequiredOnCreate]
     * @param  bool  $optionalOnUpdate  from #[OptionalOnUpdate]
     * @param  bool  $mergeOnUpdate  from #[MergeOnUpdate]
     */
    public function __construct(
        public ReflectionProperty $reflection,
        public AttributesCollection $attributes,
        public KeyPath $path,
        public Destination $destination,
        public ?string $dateFormat,
        public bool $optional,
        public ?Type $discriminated = null,
        public bool $sensitive = false,
        public bool $fallsBackToBody = false,
        public bool $required = false,
        public bool $requiredOnCreate = false,
        public bool $optionalOnUpdate = false,
        public bool $mergeOnUpdate = false,
    ) {}

    public function isRequiredFor(Operation $operation): bool
    {
        return $operation->requires($this->required, $this->requiredOnCreate, $this->optionalOnUpdate);
    }
}

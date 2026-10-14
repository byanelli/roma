<?php

namespace BYanelli\Roma\Data\Types;

use BYanelli\Roma\Data\Type;

/**
 * A discriminated union: an object whose `$key` value selects the member class it maps into.
 */
final readonly class Union extends Type
{
    /**
     * @param  array<string, Class_>  $members  keyed by discriminator value
     * @param  ?class-string  $contract  the interface or abstract class whose class-level #[Discriminated]
     *                                   or #[AutoDiscriminated] defines the union; null for a property-level one
     */
    public function __construct(
        public string $key,
        public array $members,
        public ?string $contract = null,
    ) {}

    public function memberFor(mixed $value): ?Class_
    {
        return is_string($value) ? ($this->members[$value] ?? null) : null;
    }

    /**
     * @return list<string>
     */
    public function values(): array
    {
        return array_map(strval(...), array_keys($this->members));
    }
}

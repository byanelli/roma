<?php

namespace BYanelli\Roma\TypeScript\Types;

use BYanelli\Roma\TypeScript\Type;

/**
 * A discriminated union, rendered as `({ type: 'Card' } & Card) | ...`. A union defined on
 * an interface or abstract class is emitted once as a named type alias.
 */
final readonly class Union extends Type
{
    /**
     * @param  array<string, Interface_>  $members  keyed by discriminator value
     * @param  ?class-string  $contract  the interface or abstract class the alias is named after
     */
    public function __construct(
        public string $key,
        public array $members,
        public ?string $contract = null,
    ) {}
}

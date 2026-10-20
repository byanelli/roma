<?php

namespace BYanelli\Roma\TypeScript\Types;

use BYanelli\Roma\TypeScript\Attributes\TypeScriptName;
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
     * @param  bool  $isResponseVariant  whether this is the response alias of a union whose request and
     *                                   response members differ
     */
    public function __construct(
        public string $key,
        public array $members,
        public ?string $contract = null,
        public bool $isResponseVariant = false,
    ) {}

    /**
     * The preferred alias name, with the response suffix for a response variant, or null for a
     * union written inline.
     */
    public function preferredName(): ?string
    {
        return $this->contract === null
            ? null
            : TypeScriptName::for($this->contract).($this->isResponseVariant ? Interface_::RESPONSE_SUFFIX : '');
    }
}

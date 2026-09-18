<?php

namespace BYanelli\Roma\TypeScript\Types;

use BYanelli\Roma\TypeScript\Type;

/**
 * A union of interfaces, emitted for a property typed as a contract (an
 * interface or abstract class) whose implementations are known. Members are
 * held sorted by name so the generated file is the same every run.
 */
final readonly class Union extends Type
{
    /** @var list<Interface_> */
    public array $members;

    /**
     * @param  list<Interface_>  $members
     */
    public function __construct(array $members)
    {
        usort($members, fn (Interface_ $a, Interface_ $b) => $a->name <=> $b->name);

        $this->members = $members;
    }
}

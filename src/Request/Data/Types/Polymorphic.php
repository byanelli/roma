<?php

namespace BYanelli\Roma\Request\Data\Types;

use BYanelli\Roma\Request\Data\Type;

/**
 * A property typed as an interface or an abstract class: the declared type
 * names a contract, and the runtime value is one of its concrete
 * implementations. The contract is all this type holds — which classes satisfy
 * it is an open question the class definition cannot answer, so the TypeScript
 * generator answers it by discovering implementations on disk.
 */
final readonly class Polymorphic extends Type
{
    /**
     * @param  class-string  $class
     */
    public function __construct(
        public string $class,
    ) {}
}

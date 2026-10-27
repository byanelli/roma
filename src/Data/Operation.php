<?php

namespace BYanelli\Roma\Data;

/**
 * What a mapping's input does to a resource, which decides whether #[RequiredOnCreate] and
 * #[OptionalOnUpdate] properties are required. A request's comes from its method; an array's is
 * passed to Mapper::fromArray().
 */
enum Operation
{
    case Create;
    case Update;
    case None;

    /**
     * Whether a property is required in this operation. `$always` is whether it is required in
     * any operation, `$onCreate` whether it has #[RequiredOnCreate], and `$exceptOnUpdate` whether
     * it has #[OptionalOnUpdate].
     */
    public function requires(bool $always, bool $onCreate, bool $exceptOnUpdate): bool
    {
        return $always
            || ($onCreate && $this === self::Create)
            || ($exceptOnUpdate && $this !== self::Update);
    }
}

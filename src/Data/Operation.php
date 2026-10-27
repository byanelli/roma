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
}

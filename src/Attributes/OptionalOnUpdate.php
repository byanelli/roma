<?php

namespace BYanelli\Roma\Attributes;

use Attribute;

/**
 * Makes a property typed `T|Missing` required unless the mapping's Operation is Update, in which
 * an absent key maps to Missing. A request is an Update when it is an HTTP PUT or PATCH, as
 * Laravel's Request::isMethod() reports it; an array is one only when Mapper::fromArray() is
 * passed Operation::Update. The class definition throws when the type has no Missing, when the
 * property has a default or #[Optional], and with #[RequiredOnCreate].
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class OptionalOnUpdate {}

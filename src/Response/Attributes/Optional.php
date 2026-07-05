<?php

namespace BYanelli\Roma\Response\Attributes;

use Attribute;

/**
 * Marks a response property as optional: if it is left unset, its key is
 * omitted from the serialized output instead of throwing. Without this, a
 * property has no implicit default — leaving it unset (nullable or not) is an
 * error. Set an explicit default on the property to serialize a value when
 * unset.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Optional {}

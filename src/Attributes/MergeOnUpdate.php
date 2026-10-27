<?php

namespace BYanelli\Roma\Attributes;

use Attribute;

/**
 * On a nested object property, such as `Settings|Missing|null`, makes the model's
 * updateFromObject() merge the serialized object over the column's stored JSON object, so keys
 * the object leaves out keep their stored values. The merge is shallow. Null still clears the
 * column and Missing still leaves it alone. It has no effect elsewhere. On a property whose type
 * isn't a nested object, the class definition throws.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class MergeOnUpdate {}

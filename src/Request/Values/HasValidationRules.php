<?php

namespace BYanelli\Roma\Request\Values;

/**
 * Implemented by a value-object type that contributes its own validation rules,
 * applied at the property's key. Paired with ParsesStringValue this validates
 * the raw request string before it is parsed, so a malformed value (e.g. an
 * Authorization header with an unknown scheme) is rejected with a clean,
 * field-level message instead of surfacing the object's internal shape.
 *
 * (Pluralized from the suggested "HasValidationRule" because Roma rule sets are
 * always lists — a single rule is just a one-element list.)
 */
interface HasValidationRules
{
    /**
     * @return list<mixed>
     */
    public static function validationRules(): array;
}

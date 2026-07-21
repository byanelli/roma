<?php

namespace BYanelli\Roma\Request\Values;

/**
 * Implemented by value-object classes that hydrate from a single raw request
 * string (e.g. an Authorization or Accept header) rather than from an array.
 * The returned array is keyed by the object's own property names and is then
 * hydrated through the ordinary nested-object pipeline.
 *
 * A string-parsed value is validated as a raw string at its own key (not
 * descended into), so the parse runs only at construction time, after
 * validation has passed. A class with strict fields (e.g. an enum) should also
 * implement HasValidationRules to reject a malformed string up front; otherwise
 * a value that survives validation but fails to parse would error at
 * construction rather than as a clean validation failure.
 */
interface ParsesStringValue
{
    /**
     * @return array<string, mixed>
     */
    public static function parseString(string $raw): array;
}

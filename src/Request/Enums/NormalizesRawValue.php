<?php

namespace BYanelli\Roma\Request\Enums;

/**
 * Implemented by enums that must preprocess a raw request string before it is
 * matched to a case — e.g. stripping Content-Type parameters so
 * "application/json; charset=utf-8" matches ContentType::Json.
 */
interface NormalizesRawValue
{
    public static function normalizeRawValue(string $value): string;
}

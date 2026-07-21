<?php

namespace BYanelli\Roma\Request\Enums;

use Illuminate\Support\Str;

/**
 * The authentication scheme named by the Authorization header's first token.
 *
 * Schemes are case-insensitive on the wire (RFC 7235), so the raw token is
 * normalized to canonical casing before it is matched to a case.
 */
enum AuthScheme: string implements NormalizesRawValue
{
    case Bearer = 'Bearer';
    case Basic = 'Basic';
    case Digest = 'Digest';

    public static function normalizeRawValue(string $value): string
    {
        return Str::ucfirst(Str::lower($value));
    }
}

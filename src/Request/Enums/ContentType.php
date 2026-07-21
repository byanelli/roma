<?php

namespace BYanelli\Roma\Request\Enums;

use BYanelli\Roma\Request\Attributes\Headers\ContentType as ContentTypeHeader;
use Illuminate\Support\Str;

/**
 * Common request Content-Type media types.
 *
 * The raw Content-Type header can carry parameters (e.g.
 * "application/json; charset=utf-8"); NormalizesRawValue strips them so the
 * media type alone is matched.
 */
enum ContentType: string implements HasRequestSource, NormalizesRawValue
{
    case Json = 'application/json';
    case JsonLd = 'application/ld+json';
    case FormUrlEncoded = 'application/x-www-form-urlencoded';
    case MultipartFormData = 'multipart/form-data';
    case Html = 'text/html';
    case Text = 'text/plain';
    case Markdown = 'text/markdown';
    case Toon = 'text/toon';
    case Xml = 'application/xml';
    case TextXml = 'text/xml';
    case Csv = 'text/csv';
    case Yaml = 'application/yaml';
    case OctetStream = 'application/octet-stream';
    case Pdf = 'application/pdf';

    public static function requestSourceAttribute(): ContentTypeHeader
    {
        return new ContentTypeHeader;
    }

    public static function normalizeRawValue(string $value): string
    {
        // Drop parameters like "; charset=utf-8" and match on the media type.
        return Str::of($value)->before(';')->trim()->toString();
    }
}

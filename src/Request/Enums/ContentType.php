<?php

namespace BYanelli\Roma\Request\Enums;

use BYanelli\Roma\Request\Attributes\Headers\ContentType as ContentTypeHeader;

/**
 * Common request Content-Type media types.
 *
 * NOTE: the raw `Content-Type` header may carry parameters
 * (e.g. `application/json; charset=utf-8`) that will not match an exact enum
 * value. Matching on the media-type only is a known limitation left unsolved
 * here; see the interface docs and the accompanying report.
 */
enum ContentType: string implements HasRequestSource
{
    case Json = 'application/json';
    case FormUrlEncoded = 'application/x-www-form-urlencoded';
    case MultipartFormData = 'multipart/form-data';
    case Html = 'text/html';
    case Text = 'text/plain';
    case Xml = 'application/xml';
    case Csv = 'text/csv';
    case OctetStream = 'application/octet-stream';
    case Pdf = 'application/pdf';

    public static function requestSourceAttributes(): array
    {
        return [new ContentTypeHeader];
    }
}

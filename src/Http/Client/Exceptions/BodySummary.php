<?php

namespace BYanelli\Roma\Http\Client\Exceptions;

use BYanelli\Roma\Data\SensitiveValues;
use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;

/**
 * The start of a response body for an exception message, as Laravel's RequestException writes it,
 * with sensitive values redacted first.
 *
 * @internal
 */
final class BodySummary
{
    /**
     * @param  list<string>  $sensitive
     * @param  int|false  $truncateAt  the length to truncate at, or false for the whole body
     */
    public static function of(Response $response, array $sensitive, int|false $truncateAt): string
    {
        $redacted = new PsrResponse($response->status(), body: SensitiveValues::redact($response->body(), $sensitive));

        return (is_int($truncateAt) ? Message::bodySummary($redacted, $truncateAt) : $redacted->getBody()->getContents()) ?? '';
    }
}

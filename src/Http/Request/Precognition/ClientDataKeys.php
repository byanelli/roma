<?php

namespace BYanelli\Roma\Http\Request\Precognition;

use BYanelli\Roma\Data\RootSource;
use BYanelli\Roma\Http\Request\Sources;

/**
 * The sources a client posts as form data. Input, query, body, and file values
 * are what a client actually posts, so the client knows them by their bare,
 * source-relative names ("email", not "input.email"). Headers, cookies, route
 * parameters, and request metadata are not form fields; the client only ever
 * sees those under their source-prefixed names.
 */
readonly class ClientDataKeys
{
    private const array SOURCES = [Sources\Input::class, Sources\Query::class, Sources\Body::class, Sources\File::class];

    public function isClientData(RootSource $source): bool
    {
        return in_array($source::class, self::SOURCES, true);
    }

    /**
     * The name as the client knows it: "email" for "input.email".
     */
    public function stripSourcePrefix(RootSource $source, string $name): string
    {
        $prefix = $source->getKey().'.';

        return str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name;
    }
}

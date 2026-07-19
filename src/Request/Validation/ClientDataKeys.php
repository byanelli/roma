<?php

namespace BYanelli\Roma\Request\Validation;

use BYanelli\Roma\Request\Data\Source;
use BYanelli\Roma\Request\Data\Sources;

/**
 * The keys a client addresses as form data. Input, query, body, and file
 * values are the data a client actually posts, so the client knows them by
 * their bare, source-relative names ("email", not "input.email"). Headers,
 * cookies, route parameters, and request metadata are not form fields; the
 * client only ever sees those under their source-prefixed names.
 */
readonly class ClientDataKeys
{
    /**
     * @var list<string>
     */
    private array $roots;

    public function __construct()
    {
        $this->roots = array_map(
            fn (Source $source) => $source->getKey(),
            [new Sources\Input, new Sources\Query, new Sources\Body, new Sources\File],
        );
    }

    /**
     * Whether $plainKey addresses a value the client posts as form data.
     */
    public function isClientDataKey(string $plainKey): bool
    {
        return $this->splitSourcePrefix($plainKey) !== null;
    }

    /**
     * The key as the client knows it: the source prefix stripped from a
     * form-data key; any other key unchanged.
     */
    public function stripSourcePrefix(string $plainKey): string
    {
        return $this->splitSourcePrefix($plainKey) ?? $plainKey;
    }

    /**
     * The source-relative remainder of a form-data key, or null when the key
     * does not belong to a form-data source.
     */
    private function splitSourcePrefix(string $plainKey): ?string
    {
        [$root, $rest] = explode('.', $plainKey, 2) + [1 => null];

        return ($rest !== null && in_array($root, $this->roots, true)) ? $rest : null;
    }
}

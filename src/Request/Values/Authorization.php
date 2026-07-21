<?php

namespace BYanelli\Roma\Request\Values;

use BYanelli\Roma\Request\Attributes\Headers\Authorization as AuthorizationHeader;
use BYanelli\Roma\Request\Enums\AuthScheme;
use BYanelli\Roma\Request\Enums\HasRequestSource;

/**
 * The parsed Authorization request header, split into its scheme and
 * credentials (e.g. "Bearer eyJ..." -> AuthScheme::Bearer + "eyJ...").
 *
 * Typed on a request property it self-locates to the Authorization header and,
 * once the raw value passes the format rule below, is parsed at construction
 * time into a validated scheme and credentials.
 */
readonly class Authorization implements HasRequestSource, HasValidationRules, ParsesStringValue
{
    public function __construct(
        public AuthScheme $scheme,
        public string $credentials,
    ) {}

    public static function parseString(string $raw): array
    {
        // Split on the first run of whitespace: "<scheme> <credentials>". The
        // format rule has already guaranteed a known scheme followed by
        // non-empty credentials, so both parts are present.
        $parts = preg_split('/\s+/', trim($raw), 2) ?: [];

        return [
            'scheme' => $parts[0] ?? '',
            'credentials' => $parts[1] ?? '',
        ];
    }

    /**
     * A known scheme (matched case-insensitively, per RFC 7235) followed by at
     * least one non-whitespace credential character. Built from AuthScheme so
     * the accepted schemes never drift from the enum.
     *
     * @return list<mixed>
     */
    public static function validationRules(): array
    {
        $schemes = collect(AuthScheme::cases())
            ->map(fn (AuthScheme $scheme) => preg_quote($scheme->value, '/'))
            ->implode('|');

        return ["regex:/^(?:{$schemes})\\s+\\S/i"];
    }

    public static function requestSourceAttribute(): AuthorizationHeader
    {
        return new AuthorizationHeader;
    }

    public function isBearer(): bool
    {
        return $this->scheme === AuthScheme::Bearer;
    }

    /**
     * The decoded Basic credentials, or null when this is not a Basic header or
     * its credentials are not well-formed (invalid base64, or no colon to
     * separate the user-id from the password).
     */
    public function basic(): ?BasicCredentials
    {
        if ($this->scheme !== AuthScheme::Basic) {
            return null;
        }

        $decoded = base64_decode($this->credentials, strict: true);

        if ($decoded === false || ! str_contains($decoded, ':')) {
            return null;
        }

        // The user-id cannot contain a colon, so the first one is the separator;
        // everything after it is the password (which may be empty or contain
        // further colons).
        [$username, $password] = explode(':', $decoded, 2);

        return new BasicCredentials($username, $password);
    }
}

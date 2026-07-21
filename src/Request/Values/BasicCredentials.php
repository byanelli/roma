<?php

namespace BYanelli\Roma\Request\Values;

/**
 * The user-id and password carried by a Basic Authorization header, once its
 * base64 credentials have been decoded (RFC 7617). The user-id cannot contain a
 * colon, so the first colon separates the two; the password may be empty or
 * itself contain colons.
 */
readonly class BasicCredentials
{
    public function __construct(
        public string $username,
        public string $password,
    ) {}
}

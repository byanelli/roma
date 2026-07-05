<?php

namespace BYanelli\Roma\Request\Data;

use RuntimeException;

/**
 * Thrown when a raw request value can't be coerced to its declared type
 * (e.g. "abc" for an int). This is expected for invalid input: the mapper
 * leaves the raw value in place so validation can reject it with a proper
 * message. Genuine/unexpected errors use other exception types and are not
 * swallowed.
 */
class CoercionException extends RuntimeException {}

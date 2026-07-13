<?php

namespace BYanelli\Roma\Tests\Fixtures\Discovery;

use BYanelli\Roma\Response\Response;

/**
 * An abstract response base — a response by type, but not concrete, so
 * discovery must skip it (it is never instantiated on its own).
 */
abstract class AbstractResponseBase extends Response
{
    public string $shared = 'base';
}

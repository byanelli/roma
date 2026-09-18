<?php

namespace BYanelli\Roma\Tests\Support\Polymorphic;

use BYanelli\Roma\Response\Response;

/**
 * A second response naming the same contract, so the generator has to emit each
 * implementation once rather than once per reference.
 */
class CoverResponse extends Response
{
    public function __construct(public ThumbnailSource $cover) {}
}

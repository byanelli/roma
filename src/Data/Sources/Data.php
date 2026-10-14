<?php

namespace BYanelli\Roma\Data\Sources;

use BYanelli\Roma\Data\Source;

/**
 * The single root of a plain array mapped with ArrayPolicy.
 *
 * @internal
 */
final readonly class Data extends Source
{
    public function __construct()
    {
        parent::__construct();
    }

    public function getOwnKey(): string
    {
        return 'data';
    }
}

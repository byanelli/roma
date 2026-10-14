<?php

namespace BYanelli\Roma\Request\Sources;

use BYanelli\Roma\Data\Source;

final readonly class Cookie extends Source
{
    public function __construct()
    {
        parent::__construct();
    }

    public function getOwnKey(): string
    {
        return 'cookie';
    }
}

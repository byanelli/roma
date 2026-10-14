<?php

namespace BYanelli\Roma\Request\Sources;

use BYanelli\Roma\Data\Source;

final readonly class RouteParameter extends Source
{
    public function __construct()
    {
        parent::__construct();
    }

    public function getOwnKey(): string
    {
        return 'route';
    }
}

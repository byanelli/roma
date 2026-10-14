<?php

namespace BYanelli\Roma\Request\Sources;

use BYanelli\Roma\Data\Source;

final readonly class RequestObject_ extends Source
{
    public function __construct()
    {
        parent::__construct();
    }

    public function getOwnKey(): string
    {
        return 'request';
    }
}

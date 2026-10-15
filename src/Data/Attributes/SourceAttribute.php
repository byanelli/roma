<?php

namespace BYanelli\Roma\Data\Attributes;

use BYanelli\Roma\Data\RootSource;

interface SourceAttribute
{
    public function getSource(): RootSource;
}

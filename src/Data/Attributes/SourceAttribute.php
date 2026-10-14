<?php

namespace BYanelli\Roma\Data\Attributes;

use BYanelli\Roma\Data\Source;

interface SourceAttribute
{
    public function getSource(): Source;
}

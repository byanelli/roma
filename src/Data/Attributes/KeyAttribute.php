<?php

namespace BYanelli\Roma\Data\Attributes;

use BYanelli\Roma\Data\Type;

interface KeyAttribute
{
    public function getKey(): string;

    public function getFullKey(): string;

    public function getType(): Type;
}

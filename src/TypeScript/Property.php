<?php

namespace BYanelli\Roma\TypeScript;

readonly class Property
{
    public function __construct(
        public string $key,
        public Type $type,
        public bool $optional,
        public bool $nullable,
    ) {}
}

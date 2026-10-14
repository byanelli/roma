<?php

namespace BYanelli\Roma\Tests\Support\Recursive;

final readonly class Folder implements Entry
{
    /**
     * @param  list<Entry>  $children
     */
    public function __construct(
        public string $name,
        public array $children = [],
    ) {}
}

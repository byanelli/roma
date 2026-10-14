<?php

namespace BYanelli\Roma\Tests\Support\Recursive;

final readonly class Thread
{
    /**
     * @param  list<Comment>  $replies
     */
    public function __construct(
        public string $title,
        public array $replies = [],
        public ?Node $node = null,
        public ?Entry $root = null,
        public ?Person $person = null,
    ) {}
}

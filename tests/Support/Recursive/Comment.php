<?php

namespace BYanelli\Roma\Tests\Support\Recursive;

final readonly class Comment
{
    /**
     * @param  list<Comment>  $replies
     */
    public function __construct(
        public string $body,
        public int $likes = 0,
        public array $replies = [],
    ) {}
}

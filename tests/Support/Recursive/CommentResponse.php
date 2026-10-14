<?php

namespace BYanelli\Roma\Tests\Support\Recursive;

use BYanelli\Roma\Http\Response\Response;

class CommentResponse extends Response
{
    /**
     * @param  list<CommentResponse>  $replies
     */
    public function __construct(
        public string $body,
        public array $replies = [],
    ) {}
}

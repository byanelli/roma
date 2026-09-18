<?php

namespace BYanelli\Roma\Tests\Support\Polymorphic;

use BYanelli\Roma\Response\Response;

class ThumbnailResponse extends Response
{
    public ?ThumbnailSource $thumbnail = null;

    public function __construct(public string $title) {}
}

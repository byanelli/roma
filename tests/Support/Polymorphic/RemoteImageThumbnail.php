<?php

namespace BYanelli\Roma\Tests\Support\Polymorphic;

class RemoteImageThumbnail implements ThumbnailSource
{
    public function __construct(
        public string $url,
        public int $width,
    ) {}
}

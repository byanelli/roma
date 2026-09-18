<?php

namespace BYanelli\Roma\Tests\Support\Polymorphic;

/**
 * An abstract implementation of the contract: a contract itself, never a value,
 * so it must stay out of the union.
 */
abstract class AbstractThumbnail implements ThumbnailSource
{
    public string $caption = '';
}

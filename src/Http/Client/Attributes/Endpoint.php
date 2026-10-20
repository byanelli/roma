<?php

namespace BYanelli\Roma\Http\Client\Attributes;

use BYanelli\Roma\Http\Enums\Method;

/**
 * The method and path of a client request class, such as #[Get('/videos/{id}')]. A path is
 * appended to the pending request's base URL, and its `{name}` parameters are filled from
 * #[Path] properties and properties of the same name.
 */
abstract readonly class Endpoint
{
    /**
     * @param  ?string  $path  null when the URL comes from a #[Url] property or sendRequest()'s `url`
     */
    public function __construct(public ?string $path = null) {}

    abstract public function method(): Method;
}

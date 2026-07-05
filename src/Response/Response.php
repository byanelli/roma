<?php

namespace BYanelli\Roma\Response;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Responsable;

/**
 * Base class for response objects: define typed public properties, return it
 * from a controller, and it serializes to a JSON response — recursing into
 * nested response objects. Prefer extending this; use the IsResponsable /
 * IsArrayable traits directly if you already extend something else.
 *
 * @implements Arrayable<string, mixed>
 */
abstract class Response implements Arrayable, Responsable
{
    use IsResponsable;
}

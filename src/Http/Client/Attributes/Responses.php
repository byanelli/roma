<?php

namespace BYanelli\Roma\Http\Client\Attributes;

use Attribute;

/**
 * The response classes of a client request class, matched to a response by their class-level
 * #[Status]. A parent class's list applies to its subclasses, after their own.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Responses
{
    /**
     * @var list<class-string>
     */
    public array $classes;

    /**
     * @param  class-string  ...$classes
     */
    public function __construct(string ...$classes)
    {
        $this->classes = array_values($classes);
    }
}

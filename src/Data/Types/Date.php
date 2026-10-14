<?php

namespace BYanelli\Roma\Data\Types;

use BYanelli\Roma\Data\Type;

final readonly class Date extends Type
{
    /**
     * The concrete DateTimeInterface class the value is assigned to, or null
     * when the property is typed as the DateTimeInterface interface itself.
     *
     * @param  class-string<\DateTimeInterface>|null  $class
     * @param  ?string  $format  from #[DateFormat]: the input must match it exactly
     */
    public function __construct(
        public ?string $class = null,
        public ?string $format = null,
    ) {}

    /**
     * Whether a value of the type may be a date or contain one outside a nested object, so
     * #[DateFormat] applies to it.
     */
    public static function isIn(Type $type): bool
    {
        return match (true) {
            $type instanceof self, $type instanceof Mixed_ => true,
            $type instanceof Array_ => self::isIn($type->memberType),
            $type instanceof Shape => array_any($type->items, fn (ShapeItem $item) => self::isIn($item->type)),
            default => false,
        };
    }
}

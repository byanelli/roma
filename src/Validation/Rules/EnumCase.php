<?php

namespace BYanelli\Roma\Validation\Rules;

use Illuminate\Validation\Rules\Enum;
use ReflectionEnum;
use UnitEnum;

use function Illuminate\Support\enum_value;

/**
 * Laravel's enum rule, with a message that lists the valid values: the backing values of a
 * backed enum, or the case names of a unit enum.
 */
class EnumCase extends Enum
{
    /**
     * @param  class-string<UnitEnum>  $type
     */
    public function __construct(string $type)
    {
        parent::__construct($type);
    }

    public function passes($attribute, $value): bool
    {
        return match (true) {
            // tryFrom() converts NAN to a string for a string-backed enum, which PHP warns about.
            is_float($value) && ! is_finite($value) => false,
            // tryFrom() converts "1e3", "1.9" and 1.0 to an int, but the cast to the enum doesn't.
            $this->isIntBacked() && is_string($value) => parent::passes($attribute, filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE)),
            $this->isIntBacked() && is_float($value) => false,
            default => parent::passes($attribute, $value),
        };
    }

    private function isIntBacked(): bool
    {
        return enum_exists($this->type) && new ReflectionEnum($this->type)->getBackingType()?->getName() === 'int';
    }

    /**
     * @return list<string>
     */
    public function message(): array
    {
        /** @var class-string<UnitEnum> $type */
        $type = $this->type;
        $values = array_map(fn (UnitEnum $case) => (string) enum_value($case), $type::cases());

        return ['The :attribute field must be one of: '.implode(', ', $values).'.'];
    }
}

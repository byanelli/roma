<?php

namespace BYanelli\Roma\Validation\Rules;

use Illuminate\Validation\Rules\Enum;
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

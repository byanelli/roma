<?php

namespace BYanelli\Roma\Serialization;

enum EnumStyle
{
    /** A backed enum becomes `{name, value}`; a unit enum becomes its name. */
    case NameAndValue;

    /** A backed enum becomes its value; a unit enum becomes its name. */
    case Value;

    case Name;
}

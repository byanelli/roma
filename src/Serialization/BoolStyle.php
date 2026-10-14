<?php

namespace BYanelli\Roma\Serialization;

enum BoolStyle
{
    case Native;

    /** `'true'` and `'false'`, since a query string has no boolean type. */
    case TrueFalseStrings;
}

<?php

namespace BYanelli\Roma\Serialization;

/**
 * Where a serialized property's value is written.
 */
enum Destination
{
    case Body;
    case Header;
    case Status;
    case Query;
    case Path;
}

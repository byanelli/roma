<?php

namespace BYanelli\Roma\Attributes;

use Attribute;
use Carbon\CarbonInterval;
use RuntimeException;
use Throwable;

/**
 * The unit of a number mapped to a CarbonInterval property: with #[Unit('seconds')], 90 or "90"
 * maps as 90 seconds. A string such as "2 minutes" is still accepted. The unit is any that
 * CarbonInterval accepts, such as "milliseconds", "minutes" or "days". On a property that
 * isn't a CarbonInterval or DateInterval, this throws.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Unit
{
    public function __construct(public string $unit)
    {
        try {
            CarbonInterval::make(1, $unit);
        } catch (Throwable $e) {
            throw new RuntimeException("Roma #[Unit('{$unit}')] is not a unit CarbonInterval accepts, such as \"seconds\".", previous: $e);
        }
    }
}

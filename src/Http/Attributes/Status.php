<?php

namespace BYanelli\Roma\Http\Attributes;

use Attribute;
use BYanelli\Roma\Serialization\Destination;
use BYanelli\Roma\Serialization\DestinationAttribute;
use InvalidArgumentException;
use ReflectionClass;

/**
 * On a property, marks the int property whose value is the HTTP status code: written as the
 * status of a server response, and read from the status of a client response. A request has no
 * status, so a class mapped from one can't use it.
 *
 * On a class, gives the status the class goes with: a code (404), a range ('4xx') or 'default'.
 * A server response is sent with it when it has no #[Status] property, and a client response
 * class is matched to responses with it.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
readonly class Status implements DestinationAttribute
{
    /**
     * A code ("404"), a range ("4xx") or "default", or null on a property.
     */
    public ?string $pattern;

    /**
     * @throws InvalidArgumentException for a code outside 100-599 or an unknown pattern
     */
    public function __construct(int|string|null $code = null)
    {
        $this->pattern = $code === null ? null : self::normalize($code);
    }

    /**
     * The class-level attributes of `$class`, not its parents'.
     *
     * @param  class-string  $class
     * @return list<self>
     */
    public static function ofClass(string $class): array
    {
        $statuses = [];

        foreach (new ReflectionClass($class)->getAttributes(self::class) as $attribute) {
            $status = $attribute->newInstance();

            if ($status->pattern === null) {
                throw new InvalidArgumentException(
                    "Roma class {$class} has #[Status] without a status. On a class, give a code such as 404, a range such as '4xx', or 'default'."
                );
            }

            $statuses[] = $status;
        }

        return $statuses;
    }

    public function isDefault(): bool
    {
        return $this->pattern === 'default';
    }

    public function isRange(): bool
    {
        return $this->pattern !== null && str_ends_with($this->pattern, 'xx');
    }

    /**
     * The code, or null for a range or 'default'.
     */
    public function code(): ?int
    {
        return $this->pattern !== null && ctype_digit($this->pattern) ? (int) $this->pattern : null;
    }

    public function destination(): Destination
    {
        return Destination::Status;
    }

    public function destinationKey(): ?string
    {
        return null;
    }

    private static function normalize(int|string $code): string
    {
        $pattern = strtolower((string) $code);

        if (ctype_digit($pattern) && (int) $pattern >= 100 && (int) $pattern <= 599) {
            return (string) (int) $pattern;
        }

        if ($pattern === 'default' || preg_match('/^[1-5]xx$/', $pattern) === 1) {
            return $pattern;
        }

        $given = is_int($code) ? (string) $code : "'{$code}'";

        throw new InvalidArgumentException(
            "Roma #[Status({$given})] is not a status. Give a code from 100 to 599, a range such as '4xx', or 'default'."
        );
    }
}

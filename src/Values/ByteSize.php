<?php

namespace BYanelli\Roma\Values;

use BYanelli\Roma\Data\Values\HasValidationRules;
use BYanelli\Roma\Data\Values\ParsesStringValue;
use BYanelli\Roma\Validation\Rules\ValidByteSize;
use InvalidArgumentException;
use Stringable;

/**
 * A number of bytes, mapped from a string such as "10MB" or "512KiB", or from an int of bytes.
 * KB, MB, GB and TB are powers of 1000; KiB, MiB, GiB and TiB are powers of 1024. Units are
 * case-insensitive, and a number may have a fraction ("1.5GB") if the result is a whole number
 * of bytes.
 */
final readonly class ByteSize implements HasValidationRules, ParsesStringValue, Stringable
{
    private const array UNITS = [
        'b' => 1,
        'kb' => 1000,
        'mb' => 1000 ** 2,
        'gb' => 1000 ** 3,
        'tb' => 1000 ** 4,
        'kib' => 1024,
        'mib' => 1024 ** 2,
        'gib' => 1024 ** 3,
        'tib' => 1024 ** 4,
    ];

    public function __construct(public int $bytes)
    {
        if ($bytes < 0) {
            throw new InvalidArgumentException("A byte size can't be negative: {$bytes}.");
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function parse(string $value): self
    {
        return new self(self::bytesIn($value) ?? throw new InvalidArgumentException(
            "\"{$value}\" is not a byte size, such as \"10MB\" or \"512KiB\"."
        ));
    }

    /**
     * The number of bytes in `$value`, or null when it isn't a byte size.
     */
    public static function bytesIn(string $value): ?int
    {
        if (preg_match('/^\s*(\d+)(?:\.(\d+))?\s*([kmgt]i?b|b)?\s*$/i', $value, $matches) !== 1) {
            return null;
        }

        [, $whole, $fraction, $unit] = $matches + [1 => '', 2 => '', 3 => ''];
        $fraction = rtrim($fraction, '0');

        // Longer digit strings would saturate when cast to int.
        if (strlen(ltrim($whole, '0')) > 18 || strlen($fraction) > 18) {
            return null;
        }

        // Integer arithmetic, since 1.1 * 1000 isn't exactly 1100 as a float.
        $multiplier = self::UNITS[strtolower($unit) ?: 'b'];
        $max = intdiv(PHP_INT_MAX, $multiplier);
        $numerator = (int) $fraction;
        $divisor = 10 ** strlen($fraction);

        if ((int) $whole > $max || $numerator > $max || ($numerator * $multiplier) % $divisor !== 0) {
            return null;
        }

        $wholeBytes = (int) $whole * $multiplier;
        $fractionBytes = intdiv($numerator * $multiplier, $divisor);

        return $wholeBytes <= PHP_INT_MAX - $fractionBytes ? $wholeBytes + $fractionBytes : null;
    }

    /**
     * @return array{bytes: int}
     */
    public static function parseString(string $raw): array
    {
        return ['bytes' => self::parse($raw)->bytes];
    }

    public static function validationRules(): array
    {
        return [new ValidByteSize];
    }

    /**
     * The number of bytes, which maps back to the same size.
     */
    public function __toString(): string
    {
        return (string) $this->bytes;
    }
}

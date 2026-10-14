<?php

namespace BYanelli\Roma\Serialization;

use DateTimeInterface;

/**
 * The settings that depend on where an object is serialized rather than on its class.
 */
final readonly class Profile
{
    /**
     * @param  string  $dateFormat  used when a property has no #[DateFormat]
     * @param  BoolStyle  $queryBools  applies to values written to the query string
     * @param  list<Destination>  $destinations  the destinations properties may be written to
     */
    public function __construct(
        public EnumStyle $enums,
        public string $dateFormat,
        public BoolStyle $queryBools,
        public array $destinations,
    ) {}

    public static function response(): self
    {
        return new self(
            EnumStyle::NameAndValue,
            DateTimeInterface::ATOM,
            BoolStyle::Native,
            [Destination::Body, Destination::Header, Destination::Status],
        );
    }

    public static function clientRequest(): self
    {
        return new self(
            EnumStyle::Value,
            DateTimeInterface::ATOM,
            BoolStyle::TrueFalseStrings,
            [Destination::Body, Destination::Query, Destination::Path, Destination::Header],
        );
    }

    /**
     * Output that maps back to an equal object through ObjectMapper, for casts and caches.
     */
    public static function storage(): self
    {
        return new self(
            EnumStyle::Value,
            DateTimeInterface::ATOM,
            BoolStyle::Native,
            [Destination::Body],
        );
    }

    public function allows(Destination $destination): bool
    {
        return in_array($destination, $this->destinations, true);
    }
}

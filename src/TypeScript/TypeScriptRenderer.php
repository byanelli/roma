<?php

namespace BYanelli\Roma\TypeScript;

use BYanelli\Roma\TypeScript\Types\Interface_;
use ReflectionEnum;

readonly class TypeScriptRenderer
{
    public function __construct(
        private NamesBag $namesBag = new NamesBag,
    ) {}

    public function renderInterface(Interface_ $interface): string
    {
        $name = $this->namesBag->nameFor($interface);

        $properties = array_map(
            fn (Property $p) => '  '.$this->renderProperty($p),
            $interface->properties,
        );

        return "export interface $name {\n".implode("\n", $properties)."\n}";
    }

    private function renderProperty(Property $property): string
    {
        $type = $this->renderType($property->type);

        $key = $this->renderKey($property->key);

        return
            $key
            .($property->optional ? '?' : '')
            .': '
            .$type
            .($property->nullable ? ' | null' : '')
            .';';
    }

    private function renderType(Type $type): string
    {
        return match (true) {
            $type instanceof Types\String_ => 'string',
            $type instanceof Types\Number => 'number',
            $type instanceof Types\Boolean => 'boolean',
            $type instanceof Types\Date => 'string',
            $type instanceof Types\Enum => $this->renderEnumUnion($type->class),
            $type instanceof Types\Array_ => $this->renderArrayType($type->memberType),
            $type instanceof Interface_ => $this->namesBag->nameFor($type),
            $type instanceof Types\File => 'Blob',
            default => 'unknown', // Mixed_ and any future type
        };
    }

    private function renderArrayType(Type $member): string
    {
        $ts = $this->renderType($member);

        // A union member (an enum) must be parenthesised before the [] suffix.
        if ($member instanceof Types\Enum) {
            $ts = "($ts)";
        }

        return $ts.'[]';
    }

    /**
     * @param  class-string<\UnitEnum>  $class
     *
     * @throws \ReflectionException
     */
    private function renderEnumUnion(string $class): string
    {
        $cases = $class::cases();

        if ($cases === []) {
            return 'never';
        }

        $reflection = new ReflectionEnum($class);
        $isInt = $reflection->isBacked() && $reflection->getBackingType()?->getName() === 'int';

        $members = array_map(
            function (\UnitEnum $case) use ($isInt): string {
                if ($case instanceof \BackedEnum) {
                    return $isInt ? (string) $case->value : $this->renderString((string) $case->value);
                }

                return $this->renderString($case->name);
            },
            $cases,
        );

        return implode(' | ', $members);
    }

    private function renderKey(string $key): string
    {
        return preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $key) === 1 ? $key : $this->renderString($key);
    }

    private function renderString(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }
}

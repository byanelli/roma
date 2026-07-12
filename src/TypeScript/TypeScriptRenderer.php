<?php

namespace BYanelli\Roma\TypeScript;

use BackedEnum;
use BYanelli\Roma\TypeScript\Attributes\TypeScriptName;
use BYanelli\Roma\TypeScript\Types\Interface_;
use ReflectionEnum;
use UnitEnum;

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
            // Enums are emitted as their own named types; reference by name.
            $type instanceof Types\Enum => TypeScriptName::for($type->class),
            $type instanceof Types\Array_ => $this->renderArrayType($type->memberType),
            $type instanceof Interface_ => $this->namesBag->nameFor($type),
            $type instanceof Types\File => 'Blob',
            default => 'unknown', // Mixed_ and any future type
        };
    }

    private function renderArrayType(Type $member): string
    {
        return $this->renderType($member).'[]';
    }

    /**
     * A backed enum renders as a companion `const` of {name, value} objects plus
     * a type alias over its values: callers get named construction (`Name.Case`)
     * and a finite, discriminated union type the compiler can narrow. A unit enum
     * renders as a string-literal union of its case names.
     *
     * @param  class-string<UnitEnum>  $class
     *
     * @throws \ReflectionException
     */
    public function renderEnum(string $class): string
    {
        $name = TypeScriptName::for($class);
        $cases = $class::cases();

        if (! new ReflectionEnum($class)->isBacked()) {
            $members = $cases === []
                ? 'never'
                : implode(' | ', array_map(fn (UnitEnum $case): string => $this->renderString($case->name), $cases));

            return "export type $name = $members;";
        }

        if ($cases === []) {
            return "export type $name = never;";
        }

        $entries = array_map(
            fn (BackedEnum $case): string => sprintf(
                '  %s: { name: %s, value: %s },',
                $this->renderKey($case->name),
                $this->renderString($case->name),
                $this->renderEnumValue($case->value),
            ),
            $cases,
        );

        return "export const $name = {\n".implode("\n", $entries)."\n} as const;\n\n"
            ."export type $name = typeof $name".'[keyof typeof '.$name.'];';
    }

    private function renderEnumValue(int|string $value): string
    {
        return is_int($value) ? (string) $value : $this->renderString($value);
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

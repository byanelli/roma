<?php

namespace BYanelli\Roma\TypeScript;

use BackedEnum;
use BYanelli\Roma\TypeScript\Types\Interface_;
use ReflectionEnum;
use UnitEnum;

readonly class TypeScriptRenderer
{
    public function __construct(
        private NamesBag $namesBag = new NamesBag,
    ) {}

    /**
     * @param  list<string>  $names  the preferred names of the types that aren't response variants
     */
    public function registerBaseNames(array $names): void
    {
        $this->namesBag->registerBaseNames($names);
    }

    public function renderInterface(Interface_ $interface): string
    {
        $name = $this->namesBag->nameFor($interface);

        $properties = array_map(
            fn (Property $p) => $this->renderDocComment($p->description, '  ').'  '.$this->renderProperty($p),
            $interface->properties,
        );

        $doc = $this->renderDocComment($interface->description, '');

        if ($properties === []) {
            return "{$doc}export interface $name {}";
        }

        return "{$doc}export interface $name {\n".implode("\n", $properties)."\n}";
    }

    /**
     * A TSDoc comment and newline, or an empty string when there is no description.
     */
    private function renderDocComment(?string $description, string $indent): string
    {
        if ($description === null || trim($description) === '') {
            return '';
        }

        $lines = explode("\n", str_replace(["\r\n", '*/'], ["\n", '*\/'], trim($description)));

        if (count($lines) === 1) {
            return "{$indent}/** {$lines[0]} */\n";
        }

        $body = array_map(fn (string $line) => rtrim("{$indent} * {$line}"), $lines);

        return "{$indent}/**\n".implode("\n", $body)."\n{$indent} */\n";
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
            $type instanceof Types\Enum => $this->namesBag->nameForEnum($type->class),
            $type instanceof Types\Array_ => $this->renderArrayType($type->memberType),
            $type instanceof Types\Record_ => 'Record<'.$this->renderType($type->keyType).', '.$this->renderType($type->valueType).'>',
            $type instanceof Types\Object_ => $this->renderObjectType($type),
            $type instanceof Interface_ => $this->namesBag->nameFor($type),
            $type instanceof Types\Union && $type->contract !== null => $this->namesBag->nameForUnion($type->contract, $type->isResponseVariant),
            $type instanceof Types\Union => $this->renderUnionMembers($type),
            $type instanceof Types\File => 'Blob',
            default => 'unknown', // Mixed_ and any future type
        };
    }

    private function renderArrayType(Type $member): string
    {
        $rendered = $this->renderType($member);

        return ($member instanceof Types\Union && $member->contract === null ? "($rendered)" : $rendered).'[]';
    }

    /**
     * A union defined on an interface or abstract class, as `export type PaymentMethod = ...;`.
     */
    public function renderUnionAlias(Types\Union $union): string
    {
        if ($union->contract === null) {
            throw new \InvalidArgumentException('Only a union defined on an interface or abstract class has an alias.');
        }

        return 'export type '.$this->namesBag->nameForUnion($union->contract, $union->isResponseVariant).' = '.$this->renderUnionMembers($union).';';
    }

    private function renderUnionMembers(Types\Union $union): string
    {
        $members = [];

        foreach ($union->members as $value => $interface) {
            $members[] = '({ '.$this->renderKey($union->key).': '.$this->renderString((string) $value).' } & '
                .$this->namesBag->nameFor($interface).')';
        }

        return implode(' | ', $members);
    }

    private function renderObjectType(Types\Object_ $type): string
    {
        if ($type->properties === []) {
            return '{}';
        }

        $properties = array_map(fn (Property $p) => rtrim($this->renderProperty($p), ';'), $type->properties);

        return '{ '.implode('; ', $properties).' }';
    }

    /**
     * @param  class-string<UnitEnum>  $class
     *
     * @throws \ReflectionException
     */
    public function renderEnum(string $class): string
    {
        return new ReflectionEnum($class)->isBacked()
            ? $this->renderBackedEnum($class)
            : $this->renderUnitEnum($class);
    }

    /**
     * A backed enum renders as a companion `const` of {name, value} objects plus
     * a type alias over its values: callers get named construction (`Name.Case`)
     * and a finite, discriminated union type the compiler can narrow.
     *
     * Note: this must be called with a BackedEnum, but PHPStan won't listen to
     * my assertion that a UnitEnum where `new ReflectionEnum($class)->isBacked()
     * == true` must be backed ¯\_(ツ)_/¯
     *
     * @param  class-string<UnitEnum>  $class
     */
    private function renderBackedEnum(string $class): string
    {
        $name = $this->namesBag->nameForEnum($class);
        $cases = $class::cases();

        if ($cases === []) {
            return "export type $name = never;";
        }

        $entries = [];

        foreach ($cases as $case) {
            if (! ($case instanceof BackedEnum)) {
                throw new \InvalidArgumentException('This function only accepts a BackedEnum');
            }

            $entries[] = sprintf(
                '  %s: { name: %s, value: %s },',
                $this->renderKey($case->name),
                $this->renderString($case->name),
                $this->renderEnumValue($case->value),
            );
        }

        return "export const $name = {\n".implode("\n", $entries)."\n} as const;\n\n"
            ."export type $name = typeof $name".'[keyof typeof '.$name.'];';
    }

    /**
     * A unit enum renders as a string-literal union of its case names.
     *
     * @param  class-string<UnitEnum>  $class
     */
    private function renderUnitEnum(string $class): string
    {
        $name = $this->namesBag->nameForEnum($class);
        $cases = $class::cases();

        $members = $cases === []
            ? 'never'
            : implode(
                ' | ',
                array_map(fn (UnitEnum $case): string => $this->renderString($case->name), $cases)
            );

        return "export type $name = $members;";
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

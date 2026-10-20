<?php

namespace BYanelli\Roma\TypeScript;

use BYanelli\Roma\TypeScript\Attributes\TypeScriptName;
use BYanelli\Roma\TypeScript\Types\Interface_;

class NamesBag
{
    /** @var array<string, true> reserved type names, to avoid collisions. */
    private array $usedNames = [];

    /** @var array<string, string> already resolved name per type identity (uniqueKey). */
    private array $alreadyResolved = [];

    /** @var array<string, true> preferred names of the types that aren't response variants. */
    private array $baseNames = [];

    /**
     * Registers the preferred names of the types that aren't response variants. A response
     * variant skips these names, so it never takes the name of another class's type.
     *
     * @param  list<string>  $names
     */
    public function registerBaseNames(array $names): void
    {
        $this->baseNames += array_fill_keys($names, true);
    }

    /**
     * The TypeScript name for an interface. Resolution is idempotent per
     * interface identity: an interface's definition and all properties that
     * reference it resolve to the same name (they are the same Interface_
     * object, sharing a uniqueKey). Two genuinely different classes that share a
     * basename still get distinct, suffixed names (e.g. Address, Address2).
     *
     * A response variant is named after its class's resolved name plus the
     * response suffix (Address2 becomes Address2Response).
     */
    public function nameFor(Interface_ $interface): string
    {
        if (! $interface->isResponseVariant) {
            return $this->reserve($interface->uniqueKey, $interface->name);
        }

        $base = $this->nameFor(new Interface_($interface->name, [], $interface->phpFqcn));

        return $this->reserve($interface->uniqueKey, $base.Interface_::RESPONSE_SUFFIX, isVariant: true);
    }

    /**
     * The TypeScript name for an enum. Enums share the flat type namespace with
     * interfaces, so they reserve through the same bag: a name clash between two
     * enums, or between an enum and an interface, is auto-suffixed (e.g. Status,
     * Status2) exactly as two interfaces would be. All references to the enum
     * resolve to the same name via its class-string identity.
     *
     * @param  class-string  $enumClass
     */
    public function nameForEnum(string $enumClass): string
    {
        return $this->reserve('enum/'.$enumClass, TypeScriptName::for($enumClass));
    }

    /**
     * The TypeScript name for the type alias of a union defined on an interface or abstract class.
     *
     * @param  class-string  $contract
     */
    public function nameForUnion(string $contract, bool $isResponseVariant = false): string
    {
        $base = $this->reserve('union/'.$contract, TypeScriptName::for($contract));

        return $isResponseVariant
            ? $this->reserve('union/'.$contract.'/response', $base.Interface_::RESPONSE_SUFFIX, isVariant: true)
            : $base;
    }

    private function reserve(string $uniqueKey, string $preferred, bool $isVariant = false): string
    {
        return $this->alreadyResolved[$uniqueKey] ??= $this->reserveName($preferred, $isVariant);
    }

    private function reserveName(string $preferred, bool $isVariant): string
    {
        $name = $preferred;
        $suffix = 2;

        while (isset($this->usedNames[$name]) || ($isVariant && isset($this->baseNames[$name]))) {
            $name = $preferred.$suffix++;
        }

        $this->usedNames[$name] = true;

        return $name;
    }
}

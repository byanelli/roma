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

    /**
     * The TypeScript name for an interface. Resolution is idempotent per
     * interface identity: an interface's definition and every property that
     * references it resolve to the same name (they are the same Interface_
     * object, sharing a uniqueKey). Two genuinely different classes that share a
     * basename still get distinct, suffixed names (e.g. Address, Address2).
     */
    public function nameFor(Interface_ $interface): string
    {
        return $this->reserve($interface->uniqueKey, $interface->name);
    }

    /**
     * The TypeScript name for an enum. Enums share the flat type namespace with
     * interfaces, so they reserve through the same bag: a name clash between two
     * enums, or between an enum and an interface, is auto-suffixed (e.g. Status,
     * Status2) exactly as two interfaces would be. Every reference to the enum
     * resolves to the same name via its class-string identity.
     *
     * @param  class-string  $enumClass
     */
    public function nameForEnum(string $enumClass): string
    {
        return $this->reserve('enum/'.$enumClass, TypeScriptName::for($enumClass));
    }

    private function reserve(string $uniqueKey, string $preferred): string
    {
        return $this->alreadyResolved[$uniqueKey] ??= $this->reserveName($preferred);
    }

    private function reserveName(string $preferred): string
    {
        $name = $preferred;
        $suffix = 2;

        while (isset($this->usedNames[$name])) {
            $name = $preferred.$suffix++;
        }

        $this->usedNames[$name] = true;

        return $name;
    }
}

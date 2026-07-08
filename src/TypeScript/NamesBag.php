<?php

namespace BYanelli\Roma\TypeScript;

use BYanelli\Roma\TypeScript\Types\Interface_;

class NamesBag
{
    /** @var array<string, true> reserved interface names, to avoid collisions. */
    private array $usedNames = [];

    /** @var array<string, string> resolved name per interface identity (uniqueKey). */
    private array $resolvedByInterface = [];

    /**
     * The TypeScript name for an interface. Resolution is idempotent per
     * interface identity: an interface's definition and every property that
     * references it resolve to the same name (they are the same Interface_
     * object, sharing a uniqueKey). Two genuinely different classes that share a
     * basename still get distinct, suffixed names (e.g. Address, Address2).
     */
    public function nameFor(Interface_ $interface): string
    {
        return $this->resolvedByInterface[$interface->uniqueKey]
            ??= $this->reserveName($interface->name);
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

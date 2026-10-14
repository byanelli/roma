<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Response\Response;
use BYanelli\Roma\TypeScript\TypeScriptGenerator;

enum TsKeyedTier: string
{
    case Free = 'free';
}

class TsKeyedAddress
{
    public function __construct(public string $city) {}
}

class TsKeyedRequest
{
    /**
     * @param  array<string, int>  $counts
     * @param  array<int, string>  $names
     * @param  array<string, TsKeyedAddress>  $addresses
     * @param  array{input: float, output: float, note?: ?string}  $price
     * @param  array<string, array<string, array{input: float, output: float}>>  $prices
     * @param  list<array{tier: TsKeyedTier}>  $tiers
     */
    public function __construct(
        public array $counts,
        public array $names,
        public array $addresses,
        public array $price,
        public array $prices,
        public array $tiers,
    ) {}
}

it('emits maps as Record and shapes as inline object types', function () {
    $ts = new TypeScriptGenerator([TsKeyedRequest::class])->generate();

    expect($ts)->toContain(<<<'TS'
        export interface TsKeyedRequestBody {
          counts: Record<string, number>;
          names: Record<number, string>;
          addresses: Record<string, TsKeyedAddress>;
          price: { input: number; output: number; note?: string | null };
          prices: Record<string, Record<string, { input: number; output: number }>>;
          tiers: { tier: TsKeyedTier }[];
        }
        TS)
        ->toContain('export interface TsKeyedAddress {')
        ->toContain('export type TsKeyedTier =');
});

class TsKeyedResponse extends Response
{
    /**
     * @param  array<string, array{city: TsKeyedAddress}>  $byName
     */
    public function __construct(public array $byName) {}
}

it('emits interfaces for classes inside maps and shapes of a response', function () {
    $ts = new TypeScriptGenerator([], [TsKeyedResponse::class])->generate();

    expect($ts)->toContain(<<<'TS'
        export interface TsKeyedResponseBody {
          byName: Record<string, { city: TsKeyedAddress }>;
        }
        TS)
        ->toContain('export interface TsKeyedAddress {');
});

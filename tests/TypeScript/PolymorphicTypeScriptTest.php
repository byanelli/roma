<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Discovery\RomaClassDiscovery;
use BYanelli\Roma\Response\Response;
use BYanelli\Roma\Tests\Support\Polymorphic\CoverResponse;
use BYanelli\Roma\Tests\Support\Polymorphic\PaymentResponse;
use BYanelli\Roma\Tests\Support\Polymorphic\ThumbnailResponse;
use BYanelli\Roma\Tests\Support\Polymorphic\UnimplementedResponse;
use BYanelli\Roma\TypeScript\TypeScriptGenerator;

/*
|--------------------------------------------------------------------------
| Contracts in generated TypeScript
|--------------------------------------------------------------------------
|
| A property typed as an interface or an abstract class names a shape without
| being one. The generator finds the concrete implementations itself, among the
| classes under the scanned directories, and types the property as the union of
| them. Nothing is declared in PHP, so these fixtures must be real files on disk
| for discovery to see them.
*/

$discovered = fn () => new RomaClassDiscovery()->discover([dirname(__DIR__).'/Support/Polymorphic']);

it('emits a union of the discovered implementations for an interface-typed property', function () use ($discovered) {
    $ts = new TypeScriptGenerator([], [ThumbnailResponse::class], discovered: $discovered())->generate();

    expect($ts)->toContain(<<<'TS'
        export interface ThumbnailResponseBody {
          title: string;
          thumbnail: GeneratedCoverThumbnail | RemoteImageThumbnail | null;
        }
        TS)
        ->toContain(<<<'TS'
        export interface RemoteImageThumbnail {
          url: string;
          width: number;
        }
        TS)
        ->toContain(<<<'TS'
        export interface GeneratedCoverThumbnail {
          prompt: string;
        }
        TS);
});

it('emits a union for an abstract-class-typed property', function () use ($discovered) {
    $ts = new TypeScriptGenerator([], [PaymentResponse::class], discovered: $discovered())->generate();

    expect($ts)->toContain(<<<'TS'
        export interface PaymentResponseBody {
          method: BankPayment | CardPayment;
        }
        TS)
        ->toContain(<<<'TS'
        export interface CardPayment {
          last4: string;
        }
        TS)
        ->toContain(<<<'TS'
        export interface BankPayment {
          iban: string;
        }
        TS);
});

it('leaves an abstract implementation out of the union', function () use ($discovered) {
    // AbstractThumbnail implements ThumbnailSource but is itself a contract,
    // never a value, so it describes nothing the client will receive.
    $ts = new TypeScriptGenerator([], [ThumbnailResponse::class], discovered: $discovered())->generate();

    expect($ts)->not->toContain('AbstractThumbnail');
});

it('emits an empty interface for a contract with no discovered implementations', function () use ($discovered) {
    // Nothing is known about the shape, but the property still needs a name to
    // point at — and that name must survive the empty-interface filter.
    $ts = new TypeScriptGenerator([], [UnimplementedResponse::class], discovered: $discovered())->generate();

    expect($ts)->toContain(<<<'TS'
        export interface UnimplementedResponseBody {
          source: UnimplementedSource;
        }
        TS)
        ->toContain('export interface UnimplementedSource {}');
});

it('emits each shared implementation interface once', function () use ($discovered) {
    $ts = new TypeScriptGenerator(
        [],
        [ThumbnailResponse::class, CoverResponse::class],
        discovered: $discovered(),
    )->generate();

    expect(substr_count($ts, 'export interface RemoteImageThumbnail {'))->toBe(1)
        ->and(substr_count($ts, 'export interface GeneratedCoverThumbnail {'))->toBe(1)
        ->and($ts)->toContain('thumbnail: GeneratedCoverThumbnail | RemoteImageThumbnail | null;')
        ->and($ts)->toContain('cover: GeneratedCoverThumbnail | RemoteImageThumbnail;');
});

it('falls back to an empty interface when the generator is given no discovery information', function () {
    // The old two-argument construction: nothing was scanned, so no
    // implementation can be named, and the contract stands in for them.
    $ts = new TypeScriptGenerator([], [ThumbnailResponse::class])->generate();

    expect($ts)->toContain(<<<'TS'
        export interface ThumbnailResponseBody {
          title: string;
          thumbnail: ThumbnailSource | null;
        }
        TS)
        ->toContain('export interface ThumbnailSource {}')
        ->not->toContain('RemoteImageThumbnail');
});

class TsDatedResponse extends Response
{
    public function __construct(
        public DateTimeInterface $publishedAt,
    ) {}
}

it('still treats DateTimeInterface as a date, not a contract', function () use ($discovered) {
    $ts = new TypeScriptGenerator([], [TsDatedResponse::class], discovered: $discovered())->generate();

    expect($ts)->toContain(<<<'TS'
        export interface TsDatedResponseBody {
          publishedAt: string;
        }
        TS)
        ->not->toContain('DateTimeInterface');
});

<?php

use BYanelli\Roma\Discovery\DiscoveredClasses;
use BYanelli\Roma\Discovery\RomaClassDiscovery;
use BYanelli\Roma\Tests\Fixtures\Discovery\AbstractResponseBase;
use BYanelli\Roma\Tests\Fixtures\Discovery\PlainSample;
use BYanelli\Roma\Tests\Fixtures\Discovery\ResponsableSample;
use BYanelli\Roma\Tests\Fixtures\Discovery\SampleRequest;
use BYanelli\Roma\Tests\Fixtures\Discovery\SampleResponse;
use BYanelli\Roma\Tests\Support\Polymorphic\AbstractThumbnail;
use BYanelli\Roma\Tests\Support\Polymorphic\CardPayment;
use BYanelli\Roma\Tests\Support\Polymorphic\GeneratedCoverThumbnail;
use BYanelli\Roma\Tests\Support\Polymorphic\RemoteImageThumbnail;
use BYanelli\Roma\Tests\Support\Polymorphic\ThumbnailSource;

$fixtures = fn () => dirname(__DIR__).'/Fixtures/Discovery';

it('detects requests by their class-level #[Request] attribute', function () use ($fixtures) {
    $discovered = new RomaClassDiscovery()->discover([$fixtures()]);

    expect($discovered->requests)->toEqual([SampleRequest::class]);
});

it('detects responses that extend Response or use IsResponsable', function () use ($fixtures) {
    $discovered = new RomaClassDiscovery()->discover([$fixtures()]);

    expect($discovered->responses)
        ->toContain(SampleResponse::class)
        ->toContain(ResponsableSample::class);
});

it('ignores plain classes and abstract response bases', function () use ($fixtures) {
    $discovered = new RomaClassDiscovery()->discover([$fixtures()]);

    expect([...$discovered->requests, ...$discovered->responses])
        ->not->toContain(PlainSample::class)
        ->not->toContain(AbstractResponseBase::class);
});

it('returns nothing for a directory that does not exist', function () {
    $discovered = new RomaClassDiscovery()->discover([__DIR__.'/does-not-exist']);

    expect($discovered->requests)->toBe([])
        ->and($discovered->responses)->toBe([])
        ->and($discovered->classes)->toBe([]);
});

// --- Implementations of a contract ---

it('keeps every concrete class it scanned, not only requests and responses', function () use ($fixtures) {
    $discovered = new RomaClassDiscovery()->discover([$fixtures()]);

    expect($discovered->classes)
        ->toContain(PlainSample::class)
        ->toContain(SampleRequest::class)
        ->toContain(SampleResponse::class)
        ->not->toContain(AbstractResponseBase::class);
});

it('names the concrete implementations of a contract, sorted', function () {
    $discovered = new DiscoveredClasses(classes: [
        RemoteImageThumbnail::class,
        GeneratedCoverThumbnail::class,
    ]);

    expect($discovered->implementationsOf(ThumbnailSource::class))
        ->toBe([GeneratedCoverThumbnail::class, RemoteImageThumbnail::class]);
});

it('leaves abstract classes and the contract itself out of the implementations', function () {
    $discovered = new DiscoveredClasses(classes: [
        AbstractThumbnail::class,
        ThumbnailSource::class,
        RemoteImageThumbnail::class,
    ]);

    expect($discovered->implementationsOf(ThumbnailSource::class))
        ->toBe([RemoteImageThumbnail::class]);
});

it('leaves classes unrelated to the contract out of the implementations', function () {
    $discovered = new DiscoveredClasses(classes: [
        CardPayment::class,
        PlainSample::class,
        RemoteImageThumbnail::class,
    ]);

    expect($discovered->implementationsOf(ThumbnailSource::class))
        ->toBe([RemoteImageThumbnail::class]);
});

it('finds nothing for a contract when nothing was scanned', function () {
    expect(new DiscoveredClasses()->implementationsOf(ThumbnailSource::class))->toBe([]);
});

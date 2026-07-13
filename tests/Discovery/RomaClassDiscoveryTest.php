<?php

use BYanelli\Roma\Discovery\RomaClassDiscovery;
use BYanelli\Roma\Tests\Fixtures\Discovery\AbstractResponseBase;
use BYanelli\Roma\Tests\Fixtures\Discovery\PlainSample;
use BYanelli\Roma\Tests\Fixtures\Discovery\ResponsableSample;
use BYanelli\Roma\Tests\Fixtures\Discovery\SampleRequest;
use BYanelli\Roma\Tests\Fixtures\Discovery\SampleResponse;

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
        ->and($discovered->responses)->toBe([]);
});

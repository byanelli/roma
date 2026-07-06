<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Tests\TestCase;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Date type mapping
|--------------------------------------------------------------------------
|
| A date property may be typed as any DateTimeInterface implementor. Each must
| hydrate to a value assignable to its declared type — mutable and immutable
| alike — rather than being misread as a nested request object or crashing on
| assignment.
*/

readonly class DateInterfaceRequest
{
    public DateTimeInterface $when;
}

it('maps the bare DateTimeInterface', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['when' => '2024-01-01']);

    $req = $this->mapRequest(DateInterfaceRequest::class);

    expect($req->when)->toBeInstanceOf(DateTimeInterface::class)
        ->and($req->when->format('Y-m-d'))->toBe('2024-01-01');
});

readonly class DateTimeRequest
{
    public DateTime $when;
}

it('maps DateTime', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['when' => '2024-01-01']);

    $req = $this->mapRequest(DateTimeRequest::class);

    expect($req->when)->toBeInstanceOf(DateTime::class)
        ->and($req->when->format('Y-m-d'))->toBe('2024-01-01');
});

readonly class DateTimeImmutableRequest
{
    public DateTimeImmutable $when;
}

it('maps DateTimeImmutable', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['when' => '2024-01-01']);

    $req = $this->mapRequest(DateTimeImmutableRequest::class);

    expect($req->when)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($req->when->format('Y-m-d'))->toBe('2024-01-01');
});

readonly class CarbonRequest
{
    public Carbon $when;
}

it('maps Carbon\Carbon', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['when' => '2024-01-01']);

    $req = $this->mapRequest(CarbonRequest::class);

    expect($req->when)->toBeInstanceOf(Carbon::class)
        ->and($req->when->format('Y-m-d'))->toBe('2024-01-01');
});

readonly class CarbonImmutableRequest
{
    public CarbonImmutable $when;
}

it('maps Carbon\CarbonImmutable', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['when' => '2024-01-01']);

    $req = $this->mapRequest(CarbonImmutableRequest::class);

    expect($req->when)->toBeInstanceOf(CarbonImmutable::class)
        ->and($req->when->format('Y-m-d'))->toBe('2024-01-01');
});

readonly class IlluminateCarbonRequest
{
    public Illuminate\Support\Carbon $when;
}

it('maps Illuminate\Support\Carbon', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['when' => '2024-01-01']);

    $req = $this->mapRequest(IlluminateCarbonRequest::class);

    expect($req->when)->toBeInstanceOf(Illuminate\Support\Carbon::class)
        ->and($req->when->format('Y-m-d'))->toBe('2024-01-01');
});

readonly class DateInvalidRequest
{
    public DateTimeImmutable $when;
}

it('rejects an unparseable date as a validation error', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['when' => 'not-a-date']);

    expect(fn () => $this->mapRequest(DateInvalidRequest::class))
        ->toThrow(ValidationException::class);
});

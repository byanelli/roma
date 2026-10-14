<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Http\Attributes\Header;
use BYanelli\Roma\Http\Attributes\Status;
use BYanelli\Roma\Http\Response\Response;
use BYanelli\Roma\Serialization\IsArrayable;
use BYanelli\Roma\Serialization\Profile;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;

enum OverrideColor: string
{
    case Red = 'red';
}

class ExtendedSwatch implements Arrayable, JsonSerializable
{
    use IsArrayable;

    public function __construct(public OverrideColor $color) {}

    public function toArray(?Profile $profile = null): array
    {
        return [...Serializer::toArray($this, $profile), 'shade' => OverrideColor::Red];
    }
}

class HandWrittenSwatch implements Arrayable
{
    use IsArrayable;

    public function toArray(): array
    {
        return ['custom' => true, 'color' => OverrideColor::Red];
    }
}

class PlainSwatch implements Arrayable
{
    use IsArrayable;

    public function __construct(public OverrideColor $color) {}
}

class SwatchPalette extends Response
{
    public function __construct(
        public ExtendedSwatch $extended,
        public HandWrittenSwatch $handWritten,
        public PlainSwatch $plain,
    ) {}
}

function swatchPalette(): SwatchPalette
{
    return new SwatchPalette(new ExtendedSwatch(OverrideColor::Red), new HandWrittenSwatch, new PlainSwatch(OverrideColor::Red));
}

it('calls a nested object\'s own toArray() with the response profile', function () {
    $red = ['name' => 'Red', 'value' => 'red'];

    expect(Serializer::toArray(swatchPalette()))->toBe([
        'extended' => ['color' => $red, 'shade' => $red],
        'handWritten' => ['custom' => true, 'color' => $red],
        'plain' => ['color' => $red],
    ]);
});

it('calls a nested object\'s own toArray() with the client request profile', function () {
    expect(Serializer::toArray(swatchPalette(), Profile::clientRequest()))->toBe([
        'extended' => ['color' => 'red', 'shade' => 'red'],
        'handWritten' => ['custom' => true, 'color' => 'red'],
        'plain' => ['color' => 'red'],
    ]);
});

it('serializes the top-level object by its properties', function () {
    expect(Serializer::toArray(new ExtendedSwatch(OverrideColor::Red), Profile::clientRequest()))->toBe(['color' => 'red']);
});

class LabeledSwatch implements Arrayable, JsonSerializable
{
    use IsArrayable;

    public function __construct(public OverrideColor $color) {}

    public function toArray(?Profile $profile = null): array
    {
        return [...Serializer::toArray($this, $profile), 'label' => 'warm'];
    }
}

it('gives the same output from toArray() and json_encode', function () {
    $swatch = new LabeledSwatch(OverrideColor::Red);

    expect($swatch->toArray())->toBe(['color' => ['name' => 'Red', 'value' => 'red'], 'label' => 'warm'])
        ->and(json_decode(json_encode($swatch), true))->toBe($swatch->toArray())
        ->and(json_decode(json_encode(swatchPalette()), true))->toBe(swatchPalette()->toArray());
});

class OverriddenResponse extends Response
{
    #[Status]
    public int $status = 201;

    #[Header('X-Shade')]
    public string $shade = 'warm';

    public function __construct(public OverrideColor $color) {}

    public function toArray(?Profile $profile = null): array
    {
        return [...Serializer::toArray($this, $profile), 'extra' => 'value'];
    }
}

class ReplacedBodyResponse extends Response
{
    #[Status]
    public int $status = 202;

    public function toArray(?Profile $profile = null): array
    {
        return ['replaced' => true];
    }
}

it('sends an overridden toArray() as the response body', function () {
    $response = new OverriddenResponse(OverrideColor::Red)->toResponse(new Request);

    expect($response->getData(true))->toBe(['color' => ['name' => 'Red', 'value' => 'red'], 'extra' => 'value'])
        ->and($response->getStatusCode())->toBe(201)
        ->and($response->headers->get('X-Shade'))->toBe('warm');
});

it('takes the status from the properties when the override replaces the body', function () {
    $response = new ReplacedBodyResponse()->toResponse(new Request);

    expect($response->getData(true))->toBe(['replaced' => true])
        ->and($response->getStatusCode())->toBe(202);
});

function annotations(): array
{
    return [
        'shade' => OverrideColor::Red,
        'at' => new DateTimeImmutable('2025-01-02 03:04:05', new DateTimeZone('UTC')),
        'plain' => new PlainSwatch(OverrideColor::Red),
    ];
}

class AnnotatedSwatch implements Arrayable, JsonSerializable
{
    use IsArrayable;

    public function __construct(public OverrideColor $color) {}

    public function toArray(?Profile $profile = null): array
    {
        return [...Serializer::toArray($this, $profile), ...annotations()];
    }
}

class AnnotatedResponse extends Response
{
    public function __construct(public OverrideColor $color) {}

    public function toArray(?Profile $profile = null): array
    {
        return [...Serializer::toArray($this, $profile), ...annotations()];
    }
}

class AnnotatedSwatchHolder
{
    public function __construct(public AnnotatedSwatch $swatch) {}
}

function normalizedAnnotations(): array
{
    $red = ['name' => 'Red', 'value' => 'red'];

    return ['color' => $red, 'shade' => $red, 'at' => '2025-01-02T03:04:05+00:00', 'plain' => ['color' => $red]];
}

it('returns the override\'s output unchanged from a direct toArray() call', function () {
    $array = new AnnotatedSwatch(OverrideColor::Red)->toArray();

    expect($array['shade'])->toBe(OverrideColor::Red)
        ->and($array['at'])->toBeInstanceOf(DateTimeImmutable::class)
        ->and($array['plain'])->toBeInstanceOf(PlainSwatch::class);
});

it('normalizes the override\'s output in json_encode', function () {
    expect(json_decode(json_encode(new AnnotatedSwatch(OverrideColor::Red)), true))->toBe(normalizedAnnotations())
        ->and(json_decode(json_encode(new AnnotatedResponse(OverrideColor::Red)), true))->toBe(normalizedAnnotations());
});

it('normalizes the override\'s output in toResponse()', function () {
    expect(new AnnotatedResponse(OverrideColor::Red)->toResponse(new Request)->getData(true))->toBe(normalizedAnnotations());
});

it('normalizes the override\'s output when the object is nested', function () {
    $holder = new AnnotatedSwatchHolder(new AnnotatedSwatch(OverrideColor::Red));

    expect(Serializer::toArray($holder))->toBe(['swatch' => normalizedAnnotations()])
        ->and(Serializer::toArray($holder, Profile::clientRequest()))->toBe(['swatch' => [
            'color' => 'red',
            'shade' => 'red',
            'at' => '2025-01-02T03:04:05+00:00',
            'plain' => ['color' => 'red'],
        ]]);
});

<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Response\IsArrayable;
use BYanelli\Roma\Response\Response;
use BYanelli\Roma\Serialization\Profile;
use Illuminate\Contracts\Support\Arrayable;

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

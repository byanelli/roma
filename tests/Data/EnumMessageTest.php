<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Facades\Mapper;

enum EnumMsgSize: string
{
    case Small = 's';
    case Large = 'l';
}

enum EnumMsgLevel: int
{
    case Low = 1;
    case High = 9;
}

enum EnumMsgMood
{
    case Happy;
    case Sad;
}

final readonly class EnumMsgHolder
{
    /**
     * @param  list<EnumMsgSize>  $sizes
     */
    public function __construct(
        public EnumMsgSize $size,
        public EnumMsgLevel $level,
        public EnumMsgMood $mood,
        public array $sizes = [],
    ) {}
}

it('lists the valid values of an enum in the message', function () {
    try {
        Mapper::fromArray(EnumMsgHolder::class, ['size' => 'm', 'level' => 5, 'mood' => 'Angry', 'sizes' => ['s', 'xl']]);
    } catch (MappingValidationException $e) {
        expect($e->errors())->toBe([
            'data.size' => ['The data.size field must be one of: s, l.'],
            'data.level' => ['The data.level field must be one of: 1, 9.'],
            'data.mood' => ['The data.mood field must be one of: Happy, Sad.'],
            'data.sizes.1' => ['The data.sizes.1 field must be one of: s, l.'],
        ]);

        return;
    }

    $this->fail('Expected a MappingValidationException');
});

it('maps valid enum values', function () {
    expect(Mapper::fromArray(EnumMsgHolder::class, ['size' => 'l', 'level' => '9', 'mood' => 'Sad', 'sizes' => ['s']]))
        ->toEqual(new EnumMsgHolder(EnumMsgSize::Large, EnumMsgLevel::High, EnumMsgMood::Sad, [EnumMsgSize::Small]));
});

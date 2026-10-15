<?php

namespace BYanelli\Roma\Tests\PHPStan;

use BYanelli\Roma\Data\ObjectMapper;
use BYanelli\Roma\Facades\Mapper;

use function PHPStan\Testing\assertType;

// Checked by `composer analyse`, never run.

final readonly class MappedSettings
{
    public function __construct(public string $theme) {}
}

assertType(MappedSettings::class, Mapper::fromArray(MappedSettings::class, ['theme' => 'dark']));
assertType(MappedSettings::class, app(ObjectMapper::class)->fromArray(MappedSettings::class, []));

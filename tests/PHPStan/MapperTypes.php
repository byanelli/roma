<?php

namespace BYanelli\Roma\Tests\PHPStan;

use BYanelli\Roma\Config\ConfigMapper;
use BYanelli\Roma\Data\ObjectMapper;
use BYanelli\Roma\Data\Operation;
use BYanelli\Roma\Facades\Mapper;

use function PHPStan\Testing\assertType;

// Checked by `composer analyse`, never run.

final readonly class MappedSettings
{
    public function __construct(public string $theme) {}
}

assertType(MappedSettings::class, Mapper::fromArray(MappedSettings::class, ['theme' => 'dark']));
assertType(MappedSettings::class, app(ObjectMapper::class)->fromArray(MappedSettings::class, []));
assertType(MappedSettings::class, Mapper::fromArray(MappedSettings::class, [], Operation::Update));
assertType(MappedSettings::class, Mapper::fromConfig(MappedSettings::class, 'app.settings'));
assertType(MappedSettings::class, app(ConfigMapper::class)->map(MappedSettings::class));
assertType(MappedSettings::class, Mapper::validate(new MappedSettings('dark')));
assertType(MappedSettings::class, app(ObjectMapper::class)->validate(new MappedSettings('dark'), Operation::Update));
assertType(MappedSettings::class, Mapper::validate(new MappedSettings('dark'), Operation::Update));
assertType(MappedSettings::class, Mapper::fromArray(data: [], class: MappedSettings::class));
assertType(MappedSettings::class, Mapper::markValidated(new MappedSettings('dark')));

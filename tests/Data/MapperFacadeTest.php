<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Data\ArrayInput;
use BYanelli\Roma\Data\ArrayPolicy;
use BYanelli\Roma\Data\ObjectMapper;
use BYanelli\Roma\Data\Operation;
use BYanelli\Roma\Facades\Mapper;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;

readonly class FacadeSettings
{
    public function __construct(public string $theme = 'light') {}
}

it('forwards fromArray() and validate() to an instance swapped into the facade', function () {
    Mapper::swap(new readonly class(app(ValidatorFactory::class), app()) extends ObjectMapper
    {
        public function fromArray(string $className, array $data, Operation $operation = Operation::None): object
        {
            return new FacadeSettings("swapped {$operation->name}");
        }

        public function validate(object $object, Operation $operation = Operation::None): object
        {
            return new FacadeSettings('validated');
        }
    });

    expect(Mapper::fromArray(FacadeSettings::class, [], Operation::Update))->toEqual(new FacadeSettings('swapped Update'))
        ->and(Mapper::validate(new FacadeSettings))->toEqual(new FacadeSettings('validated'));
});

it('accepts named arguments', function () {
    expect(Mapper::fromArray(data: ['theme' => 'dark'], class: FacadeSettings::class))->toEqual(new FacadeSettings('dark'))
        ->and(Mapper::validate(operation: Operation::Create, object: new FacadeSettings))->toEqual(new FacadeSettings);
});

it('forwards the other methods to the root', function () {
    expect(Mapper::map(FacadeSettings::class, new ArrayInput(['theme' => 'dark']), new ArrayPolicy))->toEqual(new FacadeSettings('dark'));
});

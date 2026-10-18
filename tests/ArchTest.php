<?php

$http = ['BYanelli\Roma\Http', 'Illuminate\Http\Request', 'Illuminate\Routing'];

arch('the data core does not depend on HTTP')
    ->expect('BYanelli\Roma\Data')
    ->not->toUse($http);

arch('the validation core does not depend on HTTP')
    ->expect('BYanelli\Roma\Validation')
    ->not->toUse($http);

arch('the serializer does not depend on HTTP')
    ->expect('BYanelli\Roma\Serialization')
    ->not->toUse($http);

arch('the generic attributes do not depend on HTTP')
    ->expect('BYanelli\Roma\Attributes')
    ->not->toUse($http);

arch('the JSON Schema builder does not depend on HTTP')
    ->expect('BYanelli\Roma\JsonSchema')
    ->not->toUse($http);

arch('the discovery cache does not depend on HTTP')
    ->expect(['BYanelli\Roma\Discovery\RomaCache', 'BYanelli\Roma\Discovery\ClassFinder'])
    ->not->toUse($http);

arch('config objects do not depend on HTTP')
    ->expect('BYanelli\Roma\Config')
    ->not->toUse($http);

arch('the generic value objects do not depend on HTTP')
    ->expect('BYanelli\Roma\Values')
    ->not->toUse($http);

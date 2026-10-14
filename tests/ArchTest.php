<?php

$http = ['Illuminate\Http\Request', 'Illuminate\Routing', 'BYanelli\Roma\Request'];

arch('the data core does not depend on HTTP')
    ->expect('BYanelli\Roma\Data')
    ->not->toUse($http);

arch('the validation core does not depend on HTTP')
    ->expect('BYanelli\Roma\Validation')
    ->not->toUse($http);

arch('the serializer does not depend on HTTP')
    ->expect('BYanelli\Roma\Serialization')
    ->not->toUse($http);

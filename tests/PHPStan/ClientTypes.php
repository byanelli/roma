<?php

namespace BYanelli\Roma\Tests\PHPStan;

use BYanelli\Roma\Facades\RomaHttp;
use BYanelli\Roma\Http\Client\Contracts\TypedFactory;
use BYanelli\Roma\Http\Client\TypedPendingRequest;
use Illuminate\Http\Client\Response;

use function PHPStan\Testing\assertType;

// Checked by `composer analyse`, never run.

final readonly class TypedVideo {}

final readonly class TypedNotFound {}

final readonly class TypedGetVideo {}

$http = app(TypedFactory::class);

assertType(TypedPendingRequest::class, $http->baseUrl('https://api.test')->withToken('t')->timeout(5));
assertType(TypedPendingRequest::class, $http->withResponses(TypedNotFound::class));
assertType(TypedVideo::class, $http->sendRequest(new TypedGetVideo, [TypedVideo::class]));
assertType(TypedNotFound::class.'|'.TypedVideo::class, $http->baseUrl('https://api.test')->sendRequest(new TypedGetVideo, [TypedVideo::class, TypedNotFound::class]));
assertType(Response::class, $http->sendRequest(new TypedGetVideo, [Response::class]));
assertType('object', $http->sendRequest(new TypedGetVideo));
assertType(TypedVideo::class, RomaHttp::sendRequest(new TypedGetVideo, [TypedVideo::class]));
assertType(TypedPendingRequest::class, RomaHttp::baseUrl('https://api.test'));

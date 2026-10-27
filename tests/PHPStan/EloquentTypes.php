<?php

namespace BYanelli\Roma\Tests\PHPStan;

use BYanelli\Roma\Eloquent\MapsDataFromObjects;
use Illuminate\Database\Eloquent\Model;

use function PHPStan\Testing\assertType;

// Checked by `composer analyse`, never run.

final class MappedModel extends Model
{
    use MapsDataFromObjects;
}

final readonly class MappedModelData
{
    public function __construct(public string $name) {}
}

assertType(MappedModel::class, MappedModel::createFrom(new MappedModelData('Ada')));
assertType('bool', new MappedModel()->updateFrom(new MappedModelData('Ada')));

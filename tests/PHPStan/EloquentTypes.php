<?php

namespace BYanelli\Roma\Tests\PHPStan;

use BYanelli\Roma\Eloquent\MapsDataFromObjects;
use Illuminate\Database\Eloquent\Model;

use function PHPStan\Testing\assertType;

// Checked by `composer analyse`, never run.

// Without @use generics, the methods accept any object.
final class MappedModel extends Model
{
    use MapsDataFromObjects;
}

final class TypedMappedModel extends Model
{
    /** @use MapsDataFromObjects<MappedModelData, MappedModelUpdate, MappedModelUpsert> */
    use MapsDataFromObjects;
}

final readonly class MappedModelData
{
    public function __construct(public string $name) {}
}

final readonly class MappedModelUpdate
{
    public function __construct(public string $name) {}
}

final readonly class MappedModelUpsert
{
    public function __construct(public string $name) {}
}

assertType(MappedModel::class, MappedModel::createFromObject(new MappedModelData('Ada')));
assertType('bool', new MappedModel()->updateFromObject(new MappedModelUpdate('Ada')));
assertType(TypedMappedModel::class, TypedMappedModel::createFromObject(new MappedModelData('Ada')));
assertType('bool', new TypedMappedModel()->updateFromObject(new MappedModelUpdate('Ada')));
assertType('int', MappedModel::upsertFromObject(new MappedModelData('Ada'), ['name']));
assertType('int', MappedModel::upsertManyFromObjects([new MappedModelData('Ada'), new MappedModelUpdate('Ada')], ['name']));
assertType('int', TypedMappedModel::upsertFromObject(new MappedModelUpsert('Ada'), ['name'], ['name']));
assertType('int', TypedMappedModel::upsertManyFromObjects([new MappedModelUpsert('Ada')], ['name']));

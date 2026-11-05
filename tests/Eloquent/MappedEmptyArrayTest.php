<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Eloquent\InvalidAttributeValue;
use BYanelli\Roma\Eloquent\Mapped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

readonly class CastListColumn
{
    /**
     * @param  list<string>  $m
     */
    public function __construct(public array $m) {}
}

readonly class CastNonEmptyListColumn
{
    /**
     * @param  non-empty-list<string>  $m
     */
    public function __construct(public array $m) {}
}

/**
 * @property ?CastListColumn $list
 * @property ?CastNonEmptyListColumn $non_empty
 */
class CastEmptyArrayRecord extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'list' => Mapped::to(CastListColumn::class),
            'non_empty' => Mapped::to(CastNonEmptyListColumn::class),
        ];
    }
}

beforeEach(function () {
    Schema::create('cast_empty_array_records', function (Blueprint $table) {
        $table->id();
        $table->json('list')->nullable();
        $table->json('non_empty')->nullable();
    });
});

it('stores a required empty array and reads it back', function () {
    $record = CastEmptyArrayRecord::query()->create(['list' => new CastListColumn([])]);

    expect(DB::table('cast_empty_array_records')->value('list'))->toBe('{"m":[]}')
        ->and(CastEmptyArrayRecord::query()->findOrFail($record->id)->list)->toEqual(new CastListColumn([]));
});

it('reads a stored {} or [] for a required array only when the key is there', function () {
    $id = DB::table('cast_empty_array_records')->insertGetId(['list' => '{"m":{}}']);

    expect(CastEmptyArrayRecord::query()->findOrFail($id)->list)->toEqual(new CastListColumn([]));

    $id = DB::table('cast_empty_array_records')->insertGetId(['list' => '{}']);

    expect(fn () => CastEmptyArrayRecord::query()->findOrFail($id)->list)->toThrow(InvalidAttributeValue::class);
});

it('throws reading [] stored for a non-empty list', function () {
    $id = DB::table('cast_empty_array_records')->insertGetId(['non_empty' => '{"m":[]}']);

    expect(fn () => CastEmptyArrayRecord::query()->findOrFail($id)->non_empty)->toThrow(InvalidAttributeValue::class);
});

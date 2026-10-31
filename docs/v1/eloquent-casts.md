---
extends: _layouts.docs.roma
section: content
title: Eloquent Casts
package: roma
version: v1
weight: 1
group: Eloquent
---

# Eloquent casts

`Mapped` casts a model attribute to a class. Any class is stored as a JSON object:
it is mapped and validated with `Mapper::fromArray()` when read or set from an array, and
stored with `Profile::storage()`, including `#[\SensitiveParameter]` properties. A class that implements `StringBacked` is stored as a
string instead.

```php
use App\Values\Postcode;
use App\Values\Settings;
use BYanelli\Roma\Eloquent\Mapped;

class Account extends Model
{
    protected function casts(): array
    {
        return [
            'settings' => Mapped::to(Settings::class),  // a JSON column
            'postcode' => Mapped::to(Postcode::class),  // a string column
        ];
    }
}
```

`Mapped::to()` returns a cast string, so it goes in the `casts()` method. In the
`$casts` property, write the same string: `Mapped::class.':'.Settings::class`.

## JSON object columns

The class is mapped from the stored JSON as `Mapper::fromArray()` maps an array, so the
class's keys, defaults, coercion and `#[Rule]`s apply, and keys the class doesn't have are
ignored. `#[SnakeCaseKeys]` on the class gives the column snake_case keys:

```php
use BYanelli\Roma\Attributes\SnakeCaseKeys;

#[SnakeCaseKeys]
readonly class Settings
{
    public function __construct(
        public string $theme = 'light',
        public int $pageSize = 20,
    ) {}
}

$account->settings = new Settings(pageSize: 50);  // stored as {"theme":"light","page_size":50}
$account->settings = ['page_size' => '50'];       // mapped first, so stored the same way
$account->settings->pageSize;                     // 50
$account->toArray()['settings'];                  // ['theme' => 'light', 'page_size' => 50]
```

An array is mapped before it is stored, and invalid data throws
`MappingValidationException`, as `Mapper::fromArray()` does. Setting a value that is neither
the class, an array nor null throws `InvalidArgumentException`. `toArray()` and `toJson()`
write the storage array without `#[\SensitiveParameter]` properties, as
`Serializer::toSafeArray()` does, so a model returned from a controller doesn't send them.

A null column reads as null. To read it as the class mapped from an empty array, pass
`defaultWhenNull`. The column stays null until the attribute is set:

```php
'settings' => Mapped::to(Settings::class, defaultWhenNull: true),

$account->settings;  // new Settings, even when the column is null
```

## String columns

A value object stored as one string, such as a postcode or a formatted identifier,
implements `StringBacked`: `parse()` returns the object for a string or null when the string
isn't one, and `toString()` returns a string that `parse()` maps back to an equal object.

```php
use BYanelli\Roma\Eloquent\StringBacked;

final readonly class Postcode implements StringBacked
{
    public function __construct(public string $digits) {}

    public static function parse(string $value): ?static
    {
        return preg_match('/^\s*(\d{5})\s*$/', $value, $m) === 1 ? new self($m[1]) : null;
    }

    public function toString(): string
    {
        return $this->digits;
    }
}

$account->postcode = Postcode::parse(' 02139 ');  // stored as "02139"
$account->postcode = ' 02139';                    // stored as " 02139"
$account->postcode->digits;                       // "02139"
$account->toArray()['postcode'];                  // " 02139", the stored string
```

An object is stored as its `toString()`. A string is stored as given, after checking that it
parses, so the column keeps the original format. `toArray()` and `toJson()` write the stored
string.

A null column reads as null. To read it as an object instead, implement `HasDefault` and pass
`defaultWhenNull`. The column stays null until the attribute is set, and `toArray()` and
`toJson()` write null for it, since they write the stored string. Setting the default object
stores its `toString()`.

```php
use BYanelli\Roma\Eloquent\HasDefault;
use BYanelli\Roma\Eloquent\StringBacked;

final readonly class Region implements HasDefault, StringBacked
{
    // parse() and toString() as above

    public static function default(): static
    {
        return new self('ZZ');
    }
}

'region' => Mapped::to(Region::class, defaultWhenNull: true),

$account->region;                  // Region::default(), even when the column is null
$account->toArray()['region'];     // null when the column is null
```

`defaultWhenNull` on a `StringBacked` class that doesn't implement `HasDefault` throws
`InvalidArgumentException` when the cast is resolved.

## Invalid values

A stored value that can't be read throws `BYanelli\Roma\Eloquent\InvalidAttributeValue`, an
`UnexpectedValueException`. That is a JSON value that isn't an object or doesn't map, or a
string that `parse()` rejects. The message gives the model's class, key and the attribute,
and the exception's `modelClass`, `modelKey` and `attribute` properties contain them. A
mapping failure is its previous exception:

```
App\Models\Account 12 has an invalid settings: it is not a JSON object
```

Pass `nullWhenInvalid` to read such a value as null instead. A `StringBacked` attribute then
also accepts a string that doesn't parse, and stores it as given, which suits a column that
keeps raw input:

```php
'postcode' => Mapped::to(Postcode::class, nullWhenInvalid: true),

$account->postcode = 'unknown';           // stored as "unknown"
$account->postcode;                       // null
$account->getAttributes()['postcode'];    // "unknown"
```

An attribute that reads as null serializes as null, since Laravel serializes a null cast
value without calling the cast.

| Option            | JSON object column                                  | String column                                                                  |
| ----------------- | --------------------------------------------------- | ------------------------------------------------------------------------------ |
| neither           | null reads as null; invalid data throws             | null reads as null; an invalid string throws                                   |
| `defaultWhenNull` | null reads as the class mapped from `[]`            | null reads as `default()`, and serializes as null; throws without `HasDefault` |
| `nullWhenInvalid` | invalid data reads as null                          | an invalid string reads as null and can be set                                 |

In a `$casts` string, the options are `default-when-null` and `null-when-invalid` after the
class: `Mapped::class.':'.Postcode::class.',null-when-invalid'`.

## Object caching

Laravel caches a cast object and writes it back through the cast when the model is saved.
`Mapped` turns that off, so reading an attribute never changes what is stored: a
column with keys the class doesn't have, a string in a format other than `toString()`'s,
and a null column read with `defaultWhenNull` are saved unchanged. Mapped classes are
usually readonly, so assign a new object to change the attribute.

## Creating and updating models

The `MapsDataFromObjects` trait creates and updates a model from a mapped object, such as a
request object. Both methods serialize the object with `Serializer::toArray()`, which uses
`Profile::storage()`, and fill the model with the result, so the model's `$fillable` and
`$guarded` apply. The object's keys are the column names, so `#[SnakeCaseKeys]` on the class
gives snake_case columns.

```php
use BYanelli\Roma\Eloquent\MapsDataFromObjects;

class Contact extends Model
{
    /** @use MapsDataFromObjects<ContactRequest, ContactRequest> */
    use MapsDataFromObjects;

    protected $fillable = ['name', 'email', 'phone'];
}

Route::post('/contacts', fn (ContactRequest $request) => Contact::createFromObject($request));
Route::put('/contacts/{contact}', fn (Contact $contact, ContactRequest $request) => $contact->updateFromObject($request));
```

`createFromObject()` serializes with `Operation::Create` and returns `static::create()`'s model.
A `Missing` property is left out, so its column takes its database default, unless the
property is required in a `Create`, such as one with `#[RequiredOnCreate]` or
`#[OptionalOnUpdate]`; then it throws `InvalidArgumentException`, listing the properties.
`updateFromObject()` serializes with `Operation::Update` and returns `update()`'s result.
`Missing` properties are left out, so their columns keep their values. With
`#[OptionalOnUpdate]` and `#[RequiredOnCreate]` (see [Validation](/docs/roma/v1/validation)), one
class can serve both routes.

By default an object property replaces its column, so keys the request leaves out are lost.
`#[MergeOnUpdate]` on a nested object property, typically `SomeInput|Missing|null`, makes
`updateFromObject()` merge the serialized object over the column's stored JSON object instead:
keys the object leaves out, such as `Missing` ones, keep their stored values, and keys it
writes replace them. The merge is shallow, so a nested object inside it is still replaced.

```php
use BYanelli\Roma\Attributes\MergeOnUpdate;

#[SnakeCaseKeys]
readonly class UpdateExportRequest
{
    public function __construct(
        #[MergeOnUpdate]
        public ExportConfigInput|Missing|null $config,
    ) {}
}

// Stored config: {"to": ["a@example.com"], "password": "secret"}
// PATCH {"config": {"to": ["b@example.com"]}} stores {"to": ["b@example.com"], "password": "secret"}
```

The column is merged by its key in the serialized array, so `#[Key]` and `#[SnakeCaseKeys]`
apply. Its stored value is read raw, without the cast, so a null column is set to the object as
it is, even under `defaultWhenNull`. Null still clears the column, and `Missing` still leaves it
alone. The attribute has no effect in `createFromObject()` or in a nested object. The class
definition throws when the property's type is not a nested object class, and
`updateFromObject()` throws `InvalidArgumentException` when the attribute has no array, JSON or
class cast, or its stored value is not a JSON object.

### Upserting

`upsertFromObject()` inserts a row from an object, or updates the row that has the same values
in the `$uniqueBy` columns, through Eloquent's `upsert()`. `upsertManyFromObjects()` does the
same for an iterable of objects in a single query, and returns 0 without a query when it is
empty. Both return the number of affected rows as the database driver reports it.

```php
Product::upsertFromObject($product, uniqueBy: ['vendor_id', 'sku']);

foreach (array_chunk($products, 500) as $chunk) {
    Product::upsertManyFromObjects($chunk, uniqueBy: ['vendor_id', 'sku'], update: ['price']);
}
```

The objects are serialized with `Operation::Create`, since a row may be inserted: a `Missing`
property that is required in a `Create` throws, and other `Missing` properties are left out, so
on update their columns keep their values. `$update` lists the columns to update when the row
exists; null, the default, updates all the row's columns except `created_at`. Eloquent sets
`created_at` and `updated_at` when the model uses timestamps.

The query builder's `upsert()` skips casts and mass assignment, so each row is filled into a
new model first and its raw attributes are sent. The model's casts apply, as do `$fillable` and
`$guarded`, so the `$uniqueBy` columns must be fillable. The model's default `$attributes` are
not used, so a column the object leaves out takes its database default on insert.

All rows in one `upsertManyFromObjects()` call must have the same columns, as the query builder
requires, so a `Missing` property must be `Missing` in all the objects or in none. Otherwise
it throws `InvalidArgumentException` before running a query. The method doesn't chunk the
input; a caller with many rows passes them in chunks, since a database limits the number of
bindings in a query.

### Type checks

The trait's three template types are the classes `createFromObject()`, `updateFromObject()` and
the upsert methods accept, which PHPStan checks. Without the `@use` tag, all of them accept any
object. A model that is never updated from an object can pass `never` as the second type, and
PHPStan then reports any call to `updateFromObject()`:

```php
/** @use MapsDataFromObjects<NewContactRequest, never> */
use MapsDataFromObjects;
```

A model that is only upserted passes `never` for the first two:

```php
/** @use MapsDataFromObjects<never, never, ImportedProduct> */
use MapsDataFromObjects;
```

### Requiring validated objects

The trait stores any object it is given, including one built with `new` that was never checked
against its rules. `Mapper::requireValidation()` makes the four methods throw
`UnvalidatedObjectException` for an object Roma didn't validate. It is off by default. As with
Eloquent's `Model::preventLazyLoading()`, turn it on in a service provider's `boot()` method,
usually outside production:

```php
use BYanelli\Roma\Facades\Mapper;

public function boot(): void
{
    Mapper::requireValidation(! $this->app->isProduction());
}
```

An object is validated when Roma built it by mapping, as a request object, a `#[RequestBody]`
or `#[RequestQuery]` parameter, a config object, an HTTP client response or the result of
`Mapper::fromArray()`, or when it passed [`Mapper::validate()`](/docs/roma/v1/validation#validate-a-constructed-object).
Only the top-level object is recorded, so a nested object stored on its own throws.
`Mapper::requireValidation(false)` turns the check off again.

Roma records the `Operation` each object was validated under. `updateFromObject()` accepts
objects validated under any operation. `createFromObject()` and the upsert methods may insert a
row, so they accept:

* an object validated with `Operation::Create`;
* an object validated with `Operation::None`, such as one from `Mapper::fromArray()` with no
  operation or from a GET request, if neither its class nor a class nested in it has a
  `#[RequiredOnCreate]` property. Only a `Create` requires those, so under `None` an empty
  string passes for one, as does a `Missing` one in a nested object. To store such an object,
  map it with `Operation::Create` or pass it to `Mapper::validate($object, Operation::Create)`.

They throw for an object validated with `Operation::Update`, such as a PUT or PATCH request
object, since an `Update` doesn't require `#[OptionalOnUpdate]` properties either.

In a test that builds a fixture with `new`, pass it to `Mapper::validate()`, which also checks
it against its rules. `Mapper::markValidated($object, $operation)` records an object as
validated without checking it, and returns it.

The check compares objects by identity, so it is reliable for immutable objects, such as
readonly classes. A change to a mutable object after validation isn't detected. A clone,
including one made with `clone($object, [...])`, and an unserialized object, such as one passed
to a queued job, are new objects and aren't validated until they are passed to
`Mapper::validate()`.

The validated objects are recorded in a `WeakMap`, which drops an entry when its object is
garbage collected, so the record doesn't grow in a long-running Octane worker. The setting from
`requireValidation()` applies to all requests the worker handles.

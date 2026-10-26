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

`AsMappedObject` casts a model attribute to a class. Any class is stored as a JSON object:
it is mapped and validated with `Mapper::fromArray()` when read or set from an array, and
stored with `Profile::storage()`, including `#[\SensitiveParameter]` properties. A class that implements `StringBacked` is stored as a
string instead.

```php
use App\Values\Postcode;
use App\Values\Settings;
use BYanelli\Roma\Eloquent\AsMappedObject;

class Account extends Model
{
    protected function casts(): array
    {
        return [
            'settings' => AsMappedObject::of(Settings::class),  // a JSON column
            'postcode' => AsMappedObject::of(Postcode::class),  // a string column
        ];
    }
}
```

`AsMappedObject::of()` returns a cast string, so it goes in the `casts()` method. In the
`$casts` property, write the same string: `AsMappedObject::class.':'.Settings::class`.

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
'settings' => AsMappedObject::of(Settings::class, defaultWhenNull: true),

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

'region' => AsMappedObject::of(Region::class, defaultWhenNull: true),

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
'postcode' => AsMappedObject::of(Postcode::class, nullWhenInvalid: true),

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
class: `AsMappedObject::class.':'.Postcode::class.',null-when-invalid'`.

## Object caching

Laravel caches a cast object and writes it back through the cast when the model is saved.
`AsMappedObject` turns that off, so reading an attribute never changes what is stored: a
column with keys the class doesn't have, a string in a format other than `toString()`'s,
and a null column read with `defaultWhenNull` are saved unchanged. Mapped classes are
usually readonly, so assign a new object to change the attribute.

---
extends: _layouts.docs.roma
section: content
title: JSON Schema
package: roma
version: v1
weight: 1
group: JSON Schema
---

# JSON Schema

Roma builds `Illuminate\JsonSchema` types from a class at runtime, so the class you map tool
input into also describes that input to the model. Nothing is generated or written to disk.

```php
use BYanelli\Roma\JsonSchema\ClassSchema;

ClassSchema::properties(SearchInput::class); // array<string, Type>, keyed by input key
ClassSchema::object(SearchInput::class);     // one ObjectType
```

`properties()` returns what the `schema()` method of a Laravel MCP or AI SDK tool returns:

```php
use App\Tools\SearchInput;
use BYanelli\Roma\JsonSchema\ClassSchema;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class SearchTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return ClassSchema::properties(SearchInput::class);
    }
}
```

The schema describes the class as it is mapped from a plain array, so a property with an
HTTP source attribute such as `#[Query]` throws. Map the tool's arguments onto the same class
with `Mapper::fromArray(SearchInput::class, $arguments)` (see
[Mapping outside a request](/docs/roma/v1/validation#mapping-outside-a-request)).

## Types

| PHP type                               | JSON Schema                                          |
| -------------------------------------- | ---------------------------------------------------- |
| `string`, `int`, `float`, `bool`       | `string`, `integer`, `number`, `boolean`             |
| a date                                 | `string` with `format: date-time`                    |
| a date with `#[DateFormat('Y-m-d')]`   | `string` with `format: date`                         |
| a date with another `#[DateFormat]`    | `string`, with the PHP format in the description     |
| `CarbonInterval`, `DateInterval`, `ByteSize` | `string`                                       |
| a backed enum                          | `enum` of its values (`string` or `integer`)         |
| a unit enum                            | `enum` of its case names                             |
| a class                                | `object`                                             |
| `list<T>`, `array<T>`, `array<int, T>` | `array` with `items`                                 |
| `array<string, T>`                     | `object`, with the value type in the description     |
| `array{a: int, b?: string}`            | `object` with `properties`                           |
| a discriminated union                  | `anyOf`, one `object` per member                     |
| `mixed` or no type                     | any JSON type                                        |

Each member of a [discriminated union](/docs/roma/v1/nested-objects#discriminated-unions) has
the discriminator key as a required property whose `enum` is its one value:

```json
{
  "anyOf": [
    {
      "type": "object",
      "properties": { "type": { "type": "string", "enum": ["Card"] }, "last4": { "type": "string" } },
      "required": ["type", "last4"]
    },
    {
      "type": "object",
      "properties": { "type": { "type": "string", "enum": ["bank"] }, "iban": { "type": "string" } },
      "required": ["type", "iban"]
    }
  ]
}
```

A property is required when it has no default, isn't nullable, isn't `#[Optional]` and
isn't typed with `Missing`, as when mapping. A nullable property allows `null`. A default that JSON can represent, such as a
scalar, an enum or an array of them, becomes the schema's `default`.

Dotted keys under one parent, such as `#[Key('error.message')]` and `#[Key('error.code')]`,
become one `object` property, `error`, which is required when one of its members is.

## Descriptions

`#[Description]` on a class or property sets the schema's `description`. On a property it
replaces the description of the property's class. The TypeScript generator writes the same
text as a doc comment.

```php
use BYanelli\Roma\Attributes\Description;

#[Description('Search the catalog.')]
readonly class SearchInput {
    public function __construct(
        #[Description('Words to match against titles.')]
        public string $query,
        public int $limit = 10,
    ) {}
}
```

## Validation rules

Rules from `#[Rule]` that have a JSON Schema equivalent add it:

| Rule                     | Keyword                                                             |
| ------------------------ | ------------------------------------------------------------------- |
| `min`, `max`, `between`, `size` | `minLength`/`maxLength` for a string, `minimum`/`maximum` for a number, `minItems`/`maxItems` for an array |
| `in`, `Rule::in()`       | `enum`                                                              |
| `email`, `url`, `uuid`   | `format: email`, `uri`, `uuid`                                      |
| `date`, `date_format`    | `format: date-time`, or as for `#[DateFormat]`                      |
| `regex`                  | `pattern`, without the PHP delimiters                               |
| `required`, `present`    | required                                                            |

Rules from `#[RulePerItem]` add their keywords to the schema of the array's `items`, so
`#[RulePerItem('email')]` on a `list<string>` gives items `format: email`.

Other rules, closure rules and a `regex` with modifiers other than `u` are left out of the
schema. The mapper still enforces them when it validates the input.

## Limitations

These follow from what Laravel's `JsonSchema` types support:

- A map such as `array<string, int>` is an `object` with no schema for its values, since
  `ObjectType` can only set `additionalProperties` to `false`. Its description gives the value
  type instead, after any `#[Description]` text: "Values: integer", "Values: Address" or
  "Values: array of string". Scalars use JSON type names and classes their short names.
- A class that contains itself, such as `list<Comment>` in `Comment`, throws, since there is
  no `$ref` to refer back to it.
- A file upload throws, since MCP and AI tool arguments are JSON, which can't carry an
  uploaded file.
- A `regex` is passed through as written after the delimiters are removed, so PHP-only syntax
  such as `\A` or possessive quantifiers isn't converted.

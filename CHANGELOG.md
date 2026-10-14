# Changelog

All notable changes to `roma` will be documented in this file.

## Unreleased

- JSON Schema from class definitions. `BYanelli\Roma\JsonSchema\ClassSchema::properties($class)`
  returns `array<string, Illuminate\JsonSchema\Types\Type>`, the shape an MCP or AI SDK
  tool's `schema()` returns, and `ClassSchema::object($class)` returns one `ObjectType`. Both
  are built at runtime from the definition the class is mapped with from a plain array.
  Scalars, dates (`format: date-time`, `date` for `#[DateFormat('Y-m-d')]`, else the PHP
  format in the description), enums (`enum` of values or case names), nested objects,
  arrays, maps, shapes and discriminated unions (`anyOf`, with the key as a single-value
  `enum` in each member) are supported. Required, nullable and defaults follow mapping.
  `min`/`max`/`between`/`size`, `in`, `email`/`url`/`uuid`/`date`/`date_format`, `regex`,
  `required` and `present` rules add keywords; other rules are left to the mapper. An
  `array<string, T>` map has no value schema, and a class that contains itself or a file
  upload throws, since Laravel's types have no `additionalProperties` schema or `$ref`.
- `#[Description('...')]` on a class or property sets the JSON Schema `description` and is
  written as a doc comment in generated TypeScript.
- Breaking: `ObjectMapper` throws `BYanelli\Roma\Data\MappingValidationException` instead of
  Laravel's `ValidationException`, so a mapping failure that escapes a controller is a 500,
  not a 422. It contains the re-keyed errors (`errors()`) and the class being mapped
  (`className()`), and its message lists the first three errors. `RequestMapper` converts it
  to a `ValidationException` with the same keys and messages, so requests, including
  precognitive ones, still get a 422. `ObjectMapper::validate()` takes the class name, and
  `validateMapping()` and `build()` split `instantiate()` into validation and construction.
- `#[Optional]` applies when mapping, and a property with it is never required. An absent
  constructor parameter takes its default, else the new `BYanelli\Roma\Missing::instance()`
  when its type includes `Missing` (`string|Missing`, `Card|Missing|null`), else `null`; one
  with none of these throws when the definition is built. An absent non-promoted property is
  left unset. A union with `Missing` is treated as its other type for coercion, validation,
  TypeScript (`bio?: string`) and serialization, and doesn't need `#[Discriminated]`. The
  serializer omits a property whose value is `Missing`, so a mapped object serializes back
  to its input.
- `#[DateFormat('Y-m-d')]` applies when mapping: the input must match the format, validated
  with Laravel's `date_format` rule in place of `date`, and is parsed with `createFromFormat`
  into the declared class (`Carbon`, `CarbonImmutable`, `DateTimeImmutable`, …). Fields the
  format doesn't contain are zero. It also applies to the dates in an array or shape
  property. Serialization is unchanged, so a mapped object serializes back to the same
  strings.
- Breaking: `#[RouteParameter]` is renamed `#[Path]` (`BYanelli\Roma\Http\Attributes\Path`),
  and its source key and error-key prefix change from `route.` to `path.`, so a missing
  parameter reports `path.id`. The accessor that returns the request path is renamed
  `#[RequestPath]`; it still reports as `request.path`.
- Breaking: classes are grouped by the kind of data they handle, and `src/Request` and
  `src/Response` are gone. Generic attributes (`Key`, `Rule`, `Present`, `Guard`,
  `DateFormat`, `Optional`, `Discriminated`, `DiscriminatorValue`) are in
  `BYanelli\Roma\Attributes`. HTTP attributes (`Header`, `Status`, `Query`, `Body`, `Cookie`,
  `Input`, `RouteParameter`, `Accessors\*`, `Headers\*`) are in `BYanelli\Roma\Http\Attributes`;
  `Method`, `ContentType`, `Scheme`, `AuthScheme` and `HasRequestSource` in
  `BYanelli\Roma\Http\Enums`; `Authorization` and `BasicCredentials` in
  `BYanelli\Roma\Http\Values`. Request mapping (`RequestMapper`, `RequestResolver`,
  `Contracts\*`, `Sources\*`) is in `BYanelli\Roma\Http\Request`, with the `#[Request]`
  marker in `Http\Request\Injection` and precognition in `Http\Request\Precognition`.
  `Response` and `IsResponsable` are in `BYanelli\Roma\Http\Response`, `IsArrayable` in
  `BYanelli\Roma\Serialization`, and the service provider is `BYanelli\Roma\RomaServiceProvider`.
- Breaking: the request and response `#[Header]` attributes are merged into one class that is
  both a mapping source and a serialization destination, so a class mapped from a request
  header writes the property back to the same header.
- Breaking: the request and response `#[Key]` attributes are merged into
  `BYanelli\Roma\Attributes\Key`, which mapping and serialization both read, so a renamed
  property maps and serializes under the same key. `#[Key]` on a top-level property sets the
  key within its source: `#[Key('page_num')] public int $page` reads `input.page_num`. A
  property that gets a key from two attributes, such as `#[Query('page_num')]` with
  `#[Key('page')]` or a response `#[Header('X-Foo')]` with `#[Key('foo')]`, throws when the
  definition is built.
- The mapping core no longer depends on HTTP. It is in `BYanelli\Roma\Data` (`ObjectMapper`,
  `ClassMapping`, `ClassDefinitionBuilder`, `Payload`, `SourcePolicy`) and
  `BYanelli\Roma\Validation`; `RequestMapper` wraps it with `RequestPayload` and
  `RequestSourcePolicy`. Breaking: the attribute interfaces (`SourceAttribute`, `KeyAttribute`,
  `RulesAttribute`, etc.) moved to `BYanelli\Roma\Data\Attributes`, `ParsesStringValue` and
  `HasValidationRules` to `BYanelli\Roma\Data\Values`, and `NormalizesRawValue` to
  `BYanelli\Roma\Data\Enums`. The public attributes are unchanged.
- Serialization moves out of `IsArrayable` into a `Serializer` service behind the
  `BYanelli\Roma\Facades\Serializer` facade: `Serializer::serialize($obj, $profile)` returns
  a `Serialized` (body, headers, status, query, path) and `Serializer::toArray()` returns the
  body. A `Profile` sets the enum style, the
  default date format, the query boolean style and the allowed destinations;
  `Profile::response()` is the default and matches the previous output, while
  `clientRequest()` and `storage()` write backed enums as their value. The profile applies
  to nested objects, and nested plain objects no longer need the trait. A destination
  attribute the profile doesn't allow throws. `Response` implements `JsonSerializable`.
  Breaking: `responseStatus()` and `responseHeaders()` are public and take the `Serialized`
  result, and `CustomizesSerialization::dateFormat()` replaces the protected
  `IsArrayable::dateFormat()` override.
- Booleans accept `true`, `1`, `"true"`, `"1"`, `"yes"`, `"on"` and `false`, `0`, `"false"`,
  `"0"`, `"no"`, `"off"`, ignoring case, in every source. Any other value, including an empty
  string, fails validation with Laravel's boolean message.
- Breaking: a property typed as an interface or an abstract class without `#[Discriminated]`
  throws when its class definition is built, in mapping and in TypeScript generation. The TypeScript union of
  implementations found by scanning the `discover` directories is removed, along with
  `DiscoveredClasses::$classes` and `implementationsOf()`.
- Discriminated unions. `#[Discriminated([Card::class, BankAccount::class], key: 'type')]` on
  an interface or abstract class, or on a property, lists the classes a value can be; it is
  required on a native union type such as `Card|Voucher`, and a property-level attribute
  replaces the class-level one. The value under the key (default `type`) selects the class:
  the member's short class name, or `#[DiscriminatorValue('bank')]`. Mapping reads the key and
  maps the rest of the object into the member; an unknown or missing value is a validation
  error at the key ("must be one of: Card, bank"). Validation rules for arrays that contain a
  union are built per element (`fallbacks.0.last4`). The class list, duplicate values and
  member properties under the key are checked when the definition is built. `list<A|B>`
  PHPDoc element types are accepted with a property-level attribute. TypeScript emits
  `({ type: 'Card' } & Card) | ({ type: 'bank' } & BankAccount)`, as a named type alias
  (`export type PaymentMethod = ...`) when the attribute is on an interface or abstract class.
  The serializer writes the key, first, for a value serialized through a discriminated
  property or an array, map or shape element of one, and not for a member serialized directly
  or through a property typed as its class. `Profile::storage()` output maps back to an equal
  object.
- A class may contain itself, directly or through other classes or a discriminated union
  (`list<Comment>` in `Comment`, `?Node $parent`). Mapping, validation, serialization and
  TypeScript support it: rules for such a structure are built from the data, errors keep
  full keys such as `input.replies.0.replies.1.body`, and TypeScript refers to the interface
  by name (`replies?: Comment[]`). A nested definition's property sources are now relative
  to the object, so one definition per class serves every place the class appears.
- A class name in a PHPDoc type resolves as PHP resolves it, through the `use` imports
  (aliases, group imports and partially qualified names such as `Billing\Card`) and namespace
  of the file that declares the property, which for a property from a trait or parent class is
  that trait's or class's file. An import takes precedence over a class of the same short name
  in the declaring namespace. A name as written, such as `DateTimeImmutable` without an import,
  is also accepted. A name that resolves to no class throws `UnresolvableClassName` when the
  definition is built, in mapping, TypeScript generation and serialization, with the class,
  property, type and the names tried. `list<self>` and `list<static>` refer to the declaring
  class.
- An invalid array element or shape item no longer leaves the other elements uncoerced.
- A validation message for an array element shows its concrete key, e.g. "The
  data.prices.m.2025-01-01.input field must be a number." instead of `data.prices.*.*.input`.
  A precognitive response shows the bare key (`codes.1`).
- `IsArrayable::toArray()` takes an optional `Profile`. A nested object whose class
  declares its own `toArray()` is serialized by calling it with the active profile, and the
  result is normalized with that profile; an override without the parameter also works. The
  object passed to `Serializer::toArray()` or `serialize()` is always serialized by its
  properties. `toResponse()` sends an override's output as the body, normalized with
  `Profile::response()`, and takes the status and headers from the properties.
  `jsonSerialize()` also normalizes an override's output, so an enum, date or nested object
  it adds is converted as a property value would be. `Serializer::normalize()` and
  `Serializer::overridesToArray()` are public.
- Maps (`array<string, T>`, `array<int, T>`) and array shapes (`array{a: int, b?: string}`)
  in `@var` and `@param`, nested to any depth along with `array<T>` and `list<T>`. Values
  are coerced and validated per key; `array<int, T>` rejects non-integer keys, and a shape's
  required keys apply only when the shape is present. The property receives a plain array.
  TypeScript emits `Record<string, T>` and inline object types. `mixed` is accepted as an
  element type.
- A required property of an object nested in an array member is now enforced; it is
  required only when that object is present and an array.
- `list<T>` array element types in `@var` and `@param`, alongside `array<T>`. A `list<T>`
  property also validates with Laravel's `list` rule, so input with non-sequential keys is
  rejected.
- `#[Content]` accessor: maps the request body exactly as sent, for signatures computed over
  the raw bytes.
- Initial development toward the 1.0 release.

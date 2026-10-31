# Changelog

All notable changes to `roma` will be documented in this file.

## Unreleased

- `Mapper::validate($object, $operation)` validates an object constructed with `new` against
  its rules and runs its guards, then returns the same instance, so it works in a pipe:
  `new JobPosting(...) |> Mapper::validate(...)`. Values are checked as they are, and arrays
  are checked for what PHP doesn't check, such as a string in a `list<int>` or a `list<Status>`.
  Properties with `#[MapFromValue]` or `#[MapFromInput]` are skipped, and `#[Limit]` adds a
  `max` of the longest string it returns. An object that contains itself fails at the key where
  it repeats. A failure throws `MappingValidationException`, keyed by the input keys without a
  source prefix.
- `SourcePolicy::mapFromInputType()` may return null to accept any `#[MapFromInput]` callable.
- Fixed: a nullable item in an array shape, such as `limit` in `array{limit: int|null}` or
  `array{limit: ?int}`, failed as required when its value was `null`. It now maps to `null`.
  The key is still required unless the item is optional (`limit?:`), and a list of shapes and a
  nullable nested shape behave the same way.
- Fixed: `null` for a required item of an array shape or member of a nested object that isn't
  nullable, such as `page` in `array{page: int}`, reported both "field is required" and the
  type's message, such as "must be an integer". It now reports only "field is required", as a
  top-level property does. This includes lists of shapes and of objects.
- Fixed: in the JSON Schema of a nullable enum, as a property, an array shape item or a member
  of a nested object, `enum` didn't include `null`, so a validator rejected `null` although
  `type` allowed it. The same applies to a nullable property with an `in:` rule.

## 0.9.2 - 2026-10-29

- `#[RequestBody]` on a controller parameter maps the request's JSON or form body onto the
  class as `Mapper::fromArray()` maps an array, without the `_method` and `_token` form fields.
  POST is a `Create`, and PUT and PATCH are an `Update`. Errors are keyed and worded as
  Laravel's validator does, such as `email` and `config.to_emails.0`, whatever
  `source_prefixed_errors` says. `HandlesFailedValidation` and Precognition work as with
  `#[Request]`. A property that reads another part of the request, such as `#[Query]` or
  `#[Header]`, throws when the class definition is built.
- `#[RequestQuery]` on a controller parameter maps the request's query string onto the class in
  the same way, with `Operation::None`. Values are coerced as they are for `#[Query]`.
- Breaking: the `Http\Request\Contracts\RequestMapper` interface has new `mapBody()` and
  `mapQuery()` methods.
- Breaking: `#[MapUsing]` is replaced by two attributes. `#[MapFromValue(callable)]` passes the
  property's value through the callable, as `#[MapUsing]` did, and never passes the input,
  whatever the parameter's type. `#[MapFromInput(callable)]` passes the whole input and sets the
  property from the result: the `Request` for `#[Request]`, the plain array for
  `Mapper::fromArray()`, `#[RequestBody]` and `#[RequestQuery]`, the config `Repository` for
  `Mapper::fromConfig()`, and the client `Response` for an HTTP client response class. The class
  definition throws when the callable's parameter doesn't accept that input. Replace a
  `#[MapUsing]` whose callable takes the `Request` with `#[MapFromInput]`, and the others with
  `#[MapFromValue]`. `SourcePolicy` has new `mapFromInputValue()` and `mapFromInputType()`
  methods.
- `ContextualBindingException` takes an optional `attribute:` argument, the short name of the
  attribute in its message.
- Fixed: an empty string for a property whose type has no `string`, such as `?int`, threw a
  `TypeError` when Laravel's `ConvertEmptyStringsToNull` middleware hadn't run, as with
  `Mapper::fromArray()`. Roma now reads `""` as `null` for such a property, and for the members
  of its arrays and shapes, before validation, so a nullable property gets `null`, a property
  with a default takes the default, and a required one fails as required. A `string`, `mixed`
  or untyped property keeps the `""`, and `#[IgnoreIf]` and `#[IgnoreIfEmpty]` still receive it.
  This changes two behaviors: `""` for a `bool` is `null`, where the docs said `false`, and
  `""` for a `#[Delimited]` array is `null` instead of `[]`.

## 0.9.1 - 2026-10-27

- `Eloquent\AsMappedObject::of()` is now `Eloquent\Mapped::to()`, with the same options.

## 0.9.0 - 2026-10-27

- `Eloquent\MapsDataFromObjects` is a trait for models. `Model::createFromObject($object)` and
  `$model->updateFromObject($object)` serialize the object with `Serializer::toArray()` under
  `Operation::Create` or `Operation::Update` and pass the array to `create()` or `update()`, so
  `$fillable` and `$guarded` apply. `Missing` properties are left out, and a `Missing` property
  that is required in a `Create` throws. The trait's template types,
  `@use MapsDataFromObjects<CreateClass, UpdateClass>`, are the classes each method accepts;
  both default to `object`, and `never` makes PHPStan reject calls to that method.
- `MapsDataFromObjects` has `Model::upsertFromObject($object, $uniqueBy, $update)` and
  `Model::upsertManyFromObjects($objects, $uniqueBy, $update)`, which serialize objects with
  `Operation::Create` and pass the rows to Eloquent's `upsert()` in one query. Each row is
  filled into a new model first, so the model's casts, `$fillable` and `$guarded` apply. Rows
  with different columns throw `InvalidArgumentException`. A third template type,
  `@use MapsDataFromObjects<CreateClass, UpdateClass, UpsertClass>`, is the class they accept.
- `#[MergeOnUpdate]` on a nested object property makes `updateFromObject()` merge the
  serialized object over the column's stored JSON object, so keys the object leaves out keep
  their stored values. The merge is shallow. Null clears the column, `Missing` leaves it alone,
  and a null column is set as it is. The class definition throws when the property's type is not
  a nested object class, and `updateFromObject()` throws when the column can't be merged.
  `Serializer::mergePaths($object)` returns the body paths of the properties to merge.
- `Serializer::serialize()` and `Serializer::toArray()` take an optional `Operation`. Under
  `Create` or `Update`, a `Missing` property that the operation requires, as when mapping,
  throws `InvalidArgumentException` instead of being omitted. `Operation::None`, the default,
  omits it as before.
- `AsMappedObject::of(Class, defaultWhenNull: true)` accepts a `StringBacked` class that
  implements the new `HasDefault` interface: a null column reads as `Class::default()`, stays
  null on save, and serializes as null. A `StringBacked` class without `HasDefault` still throws
  when the cast is resolved.
- Breaking: `Serializer::toArray($object)` and `IsArrayable::toArray()` without a profile use
  `Profile::storage()` instead of `Profile::response()`, so backed enums are written as their
  value instead of `{ name, value }`, sensitive values are written, and a `#[Status]` or
  `#[Header]` property throws. `Serializer::normalize()` has the same default.
  `Serializer::serialize()` and `jsonSerialize()`, which Laravel calls for an object returned
  from a controller, still use `Profile::response()`. To keep the old output, call
  `toResponseBody()`.
- Breaking: `Profile::storage()` writes `#[\SensitiveParameter]` properties, so stored data maps
  back to an equal object. `AsMappedObject` now stores them in the column; the model's
  `toArray()` and `toJson()` still leave them out.
- `Serializer::toSafeArray($object)` serializes with the storage settings and without
  sensitive values, for logs and other output that leaves the system, and
  `Serializer::toResponseBody($object)` with `Profile::response()`, for a response body built by
  hand. `IsArrayable` has both methods, and `jsonSerialize()` returns `toResponseBody()`.
  `Profile::withoutSensitive()` returns a copy of a profile that leaves sensitive values out.
- `#[RejectUnknownKeys]` on a class makes input keys that no property maps from fail
  validation, one error per key, such as "The input.config.k field is prohibited." under
  `input.config.k`. It applies to the class and its subclasses, at the top level and as nested
  objects, and `message:` replaces the message. A request checks only the top-level keys of its
  body, without `_method` and `_token`, and a config object only those under its prefix. On a
  property it throws when the definition is built. `Source` has new `checkedKeys()` and
  `sharesKeysWith()` methods; a custom source checks no keys unless it overrides
  `checkedKeys()`.
- Breaking: a non-empty list, such as JSON `["x"]`, for a property typed as a class, an
  object in a list or a discriminated union fails validation with "The input.config field
  must be an object." instead of mapping as an object with its defaults.
  `#[Message(object: '...')]` replaces the message. An empty list still maps as an empty
  object, since JSON `{}` decodes to one, and the top level of a mapping isn't checked.
- `#[RequiredOnCreate]` makes an optional property, such as one typed `T|Missing`, required
  when the mapping's `Data\Operation` is `Create`, so one class can serve POST and PUT. A
  request is a `Create` when it is a POST, as `Request::isMethod('POST')` reports it. Otherwise
  the property stays optional, and when required, `#[Message(required: ...)]` applies. On a
  property that is always required, it throws when the definition is built.
- `#[OptionalOnUpdate]` makes a property typed `T|Missing` required unless the mapping's
  operation is `Update`, where an absent key maps to `Missing`. A request is an `Update` when it
  is a PUT or PATCH. It throws when the definition is built on a property whose type has no
  `Missing`, on one with a default or `#[Optional]`, and with `#[RequiredOnCreate]`.
- `Data\Operation` has the cases `Create`, `Update` and `None`. `SourcePolicy` has a new
  `operation()` method, and `Mapper::fromArray()` takes an optional `Operation $operation`,
  `Operation::None` by default.
- `#[IgnoreIf(callable)]` treats a property's key as absent when the callable returns true for
  the raw input value, and `#[IgnoreIfEmpty]` does so for exactly `''` or `[]`, such as a JSON
  `{}`. An ignored value isn't validated, maps to `Missing` and isn't serialized. On a property
  whose type has no `Missing`, either throws when the definition is built.
- `#[RulePerItem(...)]` sets the validation rules for each item of an array property, in the
  forms `#[Rule]` accepts. Errors are keyed by the item, such as `input.emails.1`, the
  property's `#[Message]` applies to its items, and the rules add their JSON Schema keywords
  to the array's `items`. On a property that isn't an array, it throws when the definition is
  built.
- `Eloquent\AsMappedObject` casts a model attribute to a class:
  `AsMappedObject::of(Settings::class)`. A class is stored as a JSON object, mapped with
  `Mapper::fromArray()` when read or set from an array, and stored and serialized with
  `Profile::storage()`. A class that implements `Eloquent\StringBacked`
  (`static parse(string): ?static` and `toString()`) is stored as a string; a string set on
  the attribute is stored as given and serialized as stored. A null column reads as null, or
  with `defaultWhenNull` as the class mapped from an empty array. A stored value that can't
  be read throws `Eloquent\InvalidAttributeValue` with the model's class, key and the
  attribute, or with `nullWhenInvalid` reads as null.
- A `string` property accepts an int or float input and maps it to its string form, such as
  `35201` to `"35201"`, in the body, nested objects, lists, shapes and `Mapper::fromArray()`.
  It only accepts more input; a number used to fail validation. `#[RejectNumbers]` on a
  property or class restores the old behavior, and throws on a property without a string.
- Breaking: a `bool` property reads its input as Laravel's `Request::boolean()` does
  (`filter_var(..., FILTER_VALIDATE_BOOLEAN)`). `"1"`, `"true"`, `"on"`, `"yes"` (in any
  case), `true` and `1` are true, and any other scalar is false: `"Y"`, `"maybe"`, `2` and a
  blank string such as `?flag=` used to fail validation and are now false. Arrays and objects
  still fail with Laravel's boolean message.
- Breaking: an input value of exactly null for a property that isn't nullable but has a
  default takes the default, as if absent, instead of failing validation. With Laravel's
  `ConvertEmptyStringsToNull`, `?page=` for `int $page = 1` now gives 1 instead of a 422.
  `#[RejectNull]` on a property or class restores the old behavior; on a nullable property,
  or one without a default, it throws when the definition is built.
- `#[MapUsing(callable)]` passes a property's value through a callable after validation, and
  `Data\Attributes\MapTransform` lets an attribute of your own do the same. A callable whose
  parameter is typed as the mapping's input, such as Laravel's `Request`, receives the input
  and sets the property, which is then not read from the input or validated. A transform
  result the property's type doesn't accept throws.
- `#[Limit($limit, end: '...')]` cuts a string property with `Str::limit()` after validation.
  Null passes through and any other non-string value throws.
- `#[SerializeUsing(callable)]` passes a property's value through a callable before it is
  serialized; a callable that declares a second parameter receives the object.
  `Serialization\SerializeTransform` lets an attribute of your own do the same.
- `#[SnakeCaseKeys]` on a class gives its properties, and those of its subclasses, the
  snake_case of their names as their default keys, in mapping, serialization, TypeScript and
  the HTTP client.
- `#[Message(required: '...')]` sets a property's validation messages by Laravel rule name,
  or for all of its rules without a name. A message for `required`, `boolean` or `enum` also
  applies to the rule objects Roma checks those rules with.
- A request class that implements `Http\Request\HandlesFailedValidation` returns the
  response for a failed validation from `failedValidation()`, including a guard's
  `ValidationException` and a precognitive request's errors.
- The `source_prefixed_errors` config option. Set to `false`, input, query, body and file
  errors are keyed and worded as Laravel's validator does (`file_name`, "The file name field
  is required.").
- A typed HTTP client. `BYanelli\Roma\Http\Client\Contracts\TypedFactory` (and the `RomaHttp`
  facade) wraps the container's `Illuminate\Http\Client\Factory`, so `Http::fake()`,
  `preventStrayRequests()`, global middleware and events apply; its configuring methods return
  a `TypedPendingRequest`. `sendRequest($request, $responses, $url)` serializes a request
  object with `Profile::clientRequest()` and maps the response onto the class for its status.
  - Request classes declare the method and path with `#[Get('/videos/{id}')]`, `#[Head]`,
    `#[Post]`, `#[Put]`, `#[Patch]` or `#[Delete]`. Each property needs `#[Body]`, `#[Query]`,
    `#[Header]`, `#[Path]` or `#[Url]`, or a name in the path template, and a `#[Body]`
    property on a GET or HEAD request throws. `#[AsForm]` and `#[AsMultipart]` change the
    encoding from JSON, and a file property makes the request multipart.
  - Response classes map from the JSON body, `#[Header]` from the response headers and a
    `#[Status]` property from the status code. A class-level `#[Status]` (a code, a range such
    as `'4xx'`, or `'default'`) gives the statuses a class goes with, and a class without one
    goes with 2xx. Classes from the `sendRequest()` argument, `#[Responses]` on the request
    class and its parents, and `withResponses()` are merged by status, the earlier list
    winning.
  - A matched `Throwable` class is thrown and other classes are returned. Laravel throws and
    retries first; an unmatched status of 400 and up rethrows Laravel's exception unchanged,
    and one below 400 throws `UnmatchedResponseException`. A `'default'` fallback that fails
    to map rethrows the original exception, and another class that fails to map throws
    `MalformedResponseException`. Neither message includes the URL, and sensitive values are
    redacted. Constructor parameters typed as Laravel's `Response`, `Throwable`, `Exception` or
    `RequestException` receive the response and its exception.
  - `RomaHttp::fake()` takes request classes as keys and response objects as values,
    `RomaHttp::response()` serializes a response object for `Http::fake()`, and
    `assertSent()`, `assertNotSent()` and `sent()` match on the request class.
- `#[Status]` takes an optional code and is allowed on a class. On a server response class,
  `#[Status(201)]` sets the status when the class has no `#[Status]` property.
- Breaking: `#[Query]`, `#[Path]` and `#[Body]` are destination attributes as well as sources
  (`Serialization\FallsBackToBody`). Under a profile that doesn't allow their destination,
  such as `Profile::response()`, they are still written to the body.
  `RequestSourcePolicy::allowsDestination()` allows them along with `#[Header]`.
- Breaking: `SourcePolicy` has a `sourceFor()` method, which gives a policy the chance to read
  a source attribute's part from its own input, as the client response policy reads `#[Header]`
  from response headers. `Source::isInjected()` marks a source whose value is passed to the
  constructor as is; its property is not coerced, validated, or included in TypeScript and
  JSON Schema.
- Breaking: a `Profile` sets whether `#[\SensitiveParameter]` properties are written.
  `Profile::clientRequest()` and the new `Profile::clientResponse()` write them; the other
  profiles leave them out as before. `Profile::clientRequest()` allows the new `Url`
  destination, and `Serialized` has a `$url`.
- The serializer leaves out a header whose value is null.
- An exception class is mapped through its constructor only: public properties declared
  outside it, such as Laravel's `RequestException::$response`, are not mapped.

- Breaking: a dot in a key nests in sources with nested data (input, query, body, file
  uploads, config and `Mapper::fromArray()`), as in Laravel: `#[Key('error.message')]` reads
  `{"error": {"message": ...}}` and reports errors under `input.error.message`. It used to
  be part of the key everywhere but in config. A literal dot is escaped Laravel's way,
  `#[Key('created\.at')]`. Header, cookie, route parameter and accessor names are flat and
  keep their dots. A source says which kind it is with `Source::nestsKeys()`, which replaces
  `SourcePolicy::keyPath()`, and `Property::$wirePath`, a `KeyPath`, replaces `$wireKey`.
- The serializer writes a dotted key as nested objects, and dotted keys under one parent
  become one inline TypeScript object and one JSON Schema object. A property left out of
  the output (`#[Optional]`, `Missing`, `#[\SensitiveParameter]`) leaves no empty parent.
- A key inside another key of the same source, such as `#[Key('error')]` beside
  `#[Key('error.message')]`, throws when the definition is built and when serializing, as
  does a key with an empty segment (`a..b`). A dot in a discriminator key throws.

- Breaking: each source a class maps from reads its own data and is validated by its own
  Laravel validator. Validation rule keys are relative to the source, so a rule that refers
  to another field gives its key without the prefix: `#[Rule('required_if:type,card')]`, not
  `required_if:input.type,card`. Such a rule can't refer to a field of another source; use
  `#[Guard]` to compare values across sources. Error keys, messages and their order are
  unchanged (`input.email`, `header.X-Flag`, `request.ip`, "The input.email field is
  required."), and so are precognitive responses.
- Breaking: a source extends `BYanelli\Roma\Data\Source`, which is a part of the input
  and no longer a step in a property's key. Its key, from `getKey()` (formerly
  `getOwnKey()`), is the prefix of its error keys, `inputType()` returns the class of the
  input it reads from, such as `Illuminate\Http\Request`, and `read()` returns its data.
  `Source` is generic over that class: with `@extends Source<Request>`, PHPStan types
  `read()`'s `$input` as a `Request`. The HTTP sources extend
  `Http\Request\Sources\RequestSource`, a `Source<Request>`, and each accessor, such as
  `#[Ip]`, is its own `Sources\Accessor` source under `request.ip`, replacing
  `Sources\RequestObject_`. Only the sources a class uses are read, so a class with only
  `#[Query]` properties doesn't decode the JSON body. `SourceAttribute::getSource()`,
  `SourcePolicy::defaultSource()` and `sourceForType()` return a `Source`.
- Breaking: a property's position is a `BYanelli\Roma\Data\KeyPath`, a list of keys, in
  `Property::$keyPath`, relative to `Property::$source`, which is null in a nested object.
  `Source::$parent`, `Sources\Property`, `Property::$key`, `getKeySegments()`,
  `getRootSource()`, `getFullKey()` and `KeyAttribute::getFullKey()` are removed, and
  `Class_::sources()` replaces `rootSources()`. `RequiredWithinObject` and `DiscriminatorIn`
  take the object's `KeyPath`.
- Breaking: `ClassDefinitionBuilder::getTypeFromReflectionObject()` is private.
- Breaking: `SourcePolicy::allowsSource()` is replaced by `inputType()`. A policy allows a
  source when its input type is the source's input type or a subtype of it, and the message
  for a source it doesn't allow states both input types.
- Two different sources with the same key in one class, such as a custom source keyed
  `query` beside `#[Query]`, throw when the definition is built.
- Breaking: `AccessorAttribute` and `Property::$accessor` are removed; an accessor attribute's
  `getFromRequest()` is the public `read()`. `ClassMapping::rules()`, `attributeNames()` and
  `data()` are replaced by `validationRules()`, a `ValidationRules` per source, which
  `ObjectMapper::makeValidator()` takes. `ObjectMapper::validate()` and
  `ValidationRules::VALIDATION_ONLY_KEY` are removed. A class-level constraint such as
  `#[ContentType('application/json')]` is a rule on its source's validator.
- Breaking: two properties that map from the same key of one source throw when the
  definition is built, after the key is normalized: `#[Header('X-Flag')]` beside
  `#[Header('x-flag')]`, `#[Query('page')]` on two properties, or `#[Input('a')]` beside a
  property named `a`. Two properties of one nested class on one key throw too. The later
  one's rules used to replace the earlier one's. Several properties may still read one
  accessor, such as `#[Ajax]`. A class-level constraint such as `#[ContentType(...)]` on a
  header that a property also maps adds its rules to that key and reports its errors in that
  property's place, and a message both report appears once.
- JSON Schema: an `array<string, T>` map, which has no value schema, gives its value type in
  its description, after any `#[Description]` text: "Values: Address", "Values: integer",
  "Values: array of string". The error for a file upload says that tool input is JSON, which
  can't carry an uploaded file, and a map of file uploads throws too.
- `BYanelli\Roma\Facades\Mapper::fromArray($class, $array)` maps a plain array onto a class
  and throws `MappingValidationException` on invalid data. Its return type is the class, for
  PHPStan. Its root is `ObjectMapper`, which gains `fromArray()`. `ArrayInput` and
  `ArrayPolicy` are `@internal`.
- `#[AutoDiscriminated]` on a property or parameter throws a message that says it applies
  only to an interface or abstract class and suggests `#[Discriminated([...])]`, in mapping,
  serialization, TypeScript and JSON Schema. PHP used to throw a generic `Error` when the
  attribute was instantiated, and the serializer ignored it.
- `#[DateFormat]` on `mixed`, an untyped property, an `array` without a PHPDoc type, or an
  array of `mixed` throws when the definition is built and when serializing, as on other
  types with no date. A union with a date, such as `DateTimeImmutable|string`, is still
  allowed.
- Breaking: an empty key, as in `#[Key('')]`, `#[Query('')]` or `#[Header('')]`, throws when
  the definition is built and when serializing. It used to fall back to the property name.
- `#[Present]` on a property or parameter with a default value throws when the definition is
  built and when serializing, since the default is never used.
- `#[Discriminated]` or `#[AutoDiscriminated]` on a concrete class throws when the definition
  is built, in mapping, TypeScript and JSON Schema, and when serializing. It used to be
  ignored.
- Breaking: `#[Status]` on a property not typed `int`, including `?int`, `int|Missing` and an
  untyped property, throws when the definition is built and when serializing. The serializer
  used to cast the value with `(int)`. A status that is usually the same takes a default.
- Breaking: `#[Status]` on a class mapped from a request or a plain array throws when the
  definition is built. `SourcePolicy::allowsDestination()` decides which destination
  attributes a mapped class may have; `RequestSourcePolicy` doesn't allow `#[Status]`.
  TypeScript generation builds response classes with its own policy, which allows it.
- `#[Rule]`, `#[Present]`, or a source attribute that is only a source, such as `#[Input]` or
  an accessor, on a virtual (hooked, backing-less) property throws when the definition is
  built and when serializing, since the property is never mapped. `#[Key]`, `#[Header]`,
  `#[Query]`, `#[Body]`, `#[Path]`, `#[DateFormat]` and `#[Description]` are still allowed.
- Breaking: the key given to a source attribute, as in `#[Query('page_num')]`,
  `#[Body('display.name')]` or `#[Input('sort_by')]`, is also the key the serializer writes,
  so a request class serializes back to its own keys. It used to write the property name.
- Breaking: `#[DateFormat]` applies only to the property's own value: a date, or the dates in
  an array, map or shape. When serializing, a nested object's dates use its own formats,
  including a nested object with its own `toArray()`, an `Arrayable` or a `stdClass`, which
  used to get the parent's format. Mapping, JSON Schema and TypeScript already followed this
  rule. `#[DateFormat]` on a type with no date of its own, such as `string`, a nested class
  or `list<Event>`, throws when the definition is built and when serializing.
- A property typed with nothing but `Missing` and `null` (`Missing`, `?Missing`,
  `Missing|null`) throws when the definition is built and when serializing. The message
  says to add the type of the value, e.g. `string|Missing`.
- A property or parameter typed with `Missing` (`string|Missing`, `Card|Missing|null`) is
  optional without `#[Optional]`: it is never required, an absent key maps to
  `Missing::instance()`, JSON Schema leaves it out of `required`, TypeScript marks it
  optional in requests and responses, and the serializer omits it when unset. `#[Optional]`
  is still allowed on it. An absent non-promoted property typed with `Missing` is set to
  `Missing::instance()`, and `#[Present]` on such a type throws.
- Breaking: `#[Header]` or `#[Status]` on a property of a nested object throws when the
  definition is built, in mapping and TypeScript generation, and when the object is
  serialized, including through its own `toArray()`. The serializer used to drop it. The
  message says destination attributes such as `#[Header]` and `#[Status]` can only be declared
  on the top-level class.
- Breaking: attribute combinations with no meaning throw when the definition is built and
  when serializing, instead of being ignored: `#[Key]` with `#[Status]`, `#[Status]` with
  `#[Header]`, two source attributes such as `#[Query]` with `#[Body]`, and `#[Optional]` with
  `#[Present]`. A source attribute on an `UploadedFile` property, which always maps from the
  files, throws when the definition is built. `#[InputMapsToTypeScriptBody]` and
  `#[InputMapsToTypeScriptQuery]` throw in TypeScript generation unless they are alone on an
  `#[Input]` property of a top-level request class.
- `#[AutoDiscriminated(key: 'type')]` on an interface or abstract class makes a
  discriminated union of all concrete classes that extend or implement it in the
  `roma.discover` directories, sorted by class name. Values, `#[DiscriminatorValue]`,
  duplicate checks, mapping, validation, serialization, TypeScript and JSON Schema are the
  same as for `#[Discriminated]`. It is class-level only. `php artisan roma:cache` writes the
  members to `bootstrap/cache/roma.php` and `roma:clear` deletes it; both are registered with
  `optimizes()`, so `optimize` and `optimize:clear` run them. While the file exists it is the
  only source, even if stale, and definition errors for these unions say to run
  `roma:cache`; without it the directories are scanned once per process. A production
  deploy must run `optimize` or `roma:cache` after a member is added.
- Breaking: `roma.typescript.discover` moves to `roma.discover`, which both TypeScript
  generation and `#[AutoDiscriminated]` use. `Discriminator::attributeOf()` is replaced by
  `Discriminator::ofContract()`, which returns the union for either attribute.
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
  with none of these throws when the definition is built. An absent non-promoted property
  without `Missing` in its type is left unset. A union with `Missing` is treated as its other
  type for coercion, validation, TypeScript (`bio?: string`) and serialization, and doesn't
  need `#[Discriminated]`. The serializer omits a property whose value is `Missing`, so a
  mapped object serializes back to its input.
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
  `ClassMapping`, `ClassDefinitionBuilder`, `SourcePolicy`) and
  `BYanelli\Roma\Validation`; `RequestMapper` calls it with the request and
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
  `"0"`, `"no"`, `"off"`, ignoring case, in all sources. Any other value, including an empty
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
  to the object, so one definition per class is used wherever the class appears.
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

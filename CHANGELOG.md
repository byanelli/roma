# Changelog

All notable changes to `roma` will be documented in this file.

## Unreleased

- The mapping core no longer depends on HTTP. It is in `BYanelli\Roma\Data` (`ObjectMapper`,
  `ClassMapping`, `ClassDefinitionBuilder`, `Payload`, `SourcePolicy`) and
  `BYanelli\Roma\Validation`; `RequestMapper` wraps it with `RequestPayload` and
  `RequestSourcePolicy`. Breaking: the attribute interfaces (`SourceAttribute`, `KeyAttribute`,
  `RulesAttribute`, etc.) moved to `BYanelli\Roma\Data\Attributes`, `ParsesStringValue` and
  `HasValidationRules` to `BYanelli\Roma\Data\Values`, and `NormalizesRawValue` to
  `BYanelli\Roma\Data\Enums`. The public attributes are unchanged.
- Serialization moves out of `IsArrayable` into a `Serializer` service behind the `Roma`
  facade: `Roma::serialize($obj, $profile)` returns a `Serialized` (body, headers, status,
  query, path) and `Roma::toArray()` returns the body. A `Profile` sets the enum style, the
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
- An invalid array element or shape item no longer leaves the other elements uncoerced.
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

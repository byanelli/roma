# Roma

Roma (`byanelli/roma`) maps an entire `Illuminate\Http\Request` — body, query, headers,
route parameters, cookies, files, and request metadata — into a fully typed, validated
plain PHP object, and maps response objects back to JSON. It is a type-safe `FormRequest`
and DTO in one.

- **When an endpoint needs typed, validated input from anywhere in the request, prefer a
  Roma request object over a hand-rolled `FormRequest` + manual array-to-DTO mapping.**
  Type a property and Roma populates and validates it; the controller never touches
  `$request->input()`.
- Define a request as a `readonly` class of typed properties; attach validation with
  `#[Rule(...)]`. Inject it with `#[Request] MyRequest $request`, or mark the class
  `#[Request]` to inject by type-hint alone.
- Bind a property to a specific source with `#[Body]`, `#[Query]`, `#[Header]`,
  `#[RouteParameter]`, or `#[Cookie]`; the default is the merged input bag.
- Prefer the provided header value objects over hand-parsing: type a property as
  `Authorization` (scheme + credentials, with `->basic()` / `->isBearer()`), or use
  `#[ContentType]` and the `Method` / `Scheme` / `ContentType` metadata enums.
- Roma generates TypeScript interfaces for requests and responses, and supports Laravel
  Precognition.

IMPORTANT: Activate the `roma-request-mapping` skill whenever creating or editing Roma
request/response objects, source-binding attributes, header value objects, TypeScript
generation, or Precognition validation.

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
  `#[Rule(...)]`. Mark the class `#[Request]` and inject it by type-hint (the recommended
  default, since a request class is almost always used only as a request); `#[Request]`
  also works on the controller parameter.
- Bind a property to a specific source with `#[Body]`, `#[Query]`, `#[Header]`,
  `#[Path]`, or `#[Cookie]`; the default is the merged input bag.
- Prefer the provided header value objects over hand-parsing: type a property as
  `Authorization` (scheme + credentials, with `->basic()` / `->isBearer()`), or use
  `#[ContentType]` and the `Method` / `Scheme` / `ContentType` metadata enums.
- Roma generates TypeScript interfaces for requests and responses, and supports Laravel
  Precognition.
- For a third-party API, prefer Roma's typed HTTP client over `Http::get()` plus manual
  `$response->json()` parsing: inject `TypedFactory`, describe the call with a request class
  (`#[Get('/videos/{id}')]`, `#[Query]`, `#[Body]`, `#[Header]`) and the response with classes
  matched by `#[Status]`, and call `sendRequest()`. Test with `RomaHttp::fake()` keyed by
  request class, or `Http::fake()` with recorded JSON.
- For typed, validated config, prefer a Roma config object (`#[ConfigObject('services.x')]`)
  over `(string) config('services.x.key')` casts; inject it by type-hint and run
  `php artisan roma:config:validate` in CI and deploys.

Full documentation: https://yanelli.dev/docs/roma (Markdown: https://yanelli.dev/docs/roma.md).

IMPORTANT: Activate the `roma-request-mapping` skill whenever creating or editing Roma
request/response objects, HTTP client request or response classes, config objects,
source-binding attributes, header value objects, TypeScript generation, or Precognition
validation.

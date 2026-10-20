---
extends: _layouts.docs.roma
section: content
title: Introduction
package: roma
version: v1
weight: 1
group: Getting Started
---

# Introduction

Roma is an object mapper for Laravel. It maps data into type-safe, validated plain
PHP objects, and serializes objects back out.

For requests, Roma maps _all_ aspects of an `Illuminate\Http\Request` — headers, the query
string, the body, files, cookies, route parameters, and convenience methods like
`$request->ajax()` — into a plain PHP object. The goal: when you use a Roma request, you
never touch the underlying Laravel request directly.

On the response side, Roma converts a plain object (recursively) into the JSON body of a
`JsonResponse`, with properties that can instead drive the status code or headers.

Roma's [HTTP client](/docs/roma/v1/http-client) works in both directions for APIs you call:
it serializes a request object into the query string, body, headers and path of an outgoing
request, and maps the response onto a class chosen by its status, throwing the ones that are
exceptions. It wraps Laravel's HTTP client, so `Http::fake()` and its other features apply.

[Config objects](/docs/roma/v1/config-objects) get the same treatment for Laravel's config
repository: a class marked `#[ConfigObject('services.scrapfly')]` is mapped and validated
from that part of the config, so a missing API key is reported with its config key instead
of surfacing later as a 401. Plain arrays, such as tool input, map onto the same classes with
`Mapper::fromArray()`.

And with one command, Roma generates TypeScript definitions for both requests and
responses — one source of truth shared by your backend and frontend.

## When to reach for Roma

**When an endpoint needs typed, validated input from anywhere in the request, reach for a
Roma request object instead of a hand-rolled `FormRequest` plus a manual array-to-DTO
step.** You declare typed properties; Roma populates and validates them. It is a type-safe
`FormRequest` and DTO in one.

Mark the class `#[Request]` and type-hint it in your controller — Roma maps and validates
it before your action runs:

```php
use BYanelli\Roma\Attributes\Rule;
use BYanelli\Roma\Http\Request\Injection\Request;

#[Request]
readonly class CreateContactRequest {
    public function __construct(
        #[Rule('max:255')]
        public string $name,

        #[Rule(['email', 'unique:contacts', 'max:255'])]
        public string $email,
    ) {}
}

class CreateContactController {
    public function __invoke(CreateContactRequest $request) {
        Contact::create([
            'name'  => $request->name,
            'email' => $request->email,
        ]);
    }
}
```

The rest of these docs walk through defining requests, the sources a property can bind to,
headers and request metadata, nested objects, response objects, the HTTP client, config
objects, and TypeScript generation.

## These docs as Markdown

Prefer plain text? The entire documentation is served as a single Markdown file at
[yanelli.dev/docs/roma.md](/docs/roma.md) — hand it to an AI coding assistant, or just read
everything in one place. Each version has its own too (e.g.
[/docs/roma/v1.md](/docs/roma/v1.md)).

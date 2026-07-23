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

Roma is a **Request/Response Object MApper** for Laravel. It maps _all_ aspects of an
`Illuminate\Http\Request` — headers, the query string, the body, files, cookies, route
parameters, and convenience methods like `$request->ajax()` — into a fully type-safe,
validated plain PHP object. The goal: when you use a Roma request, you never touch the
underlying Laravel request directly.

On the response side, Roma converts a plain object (recursively) into the JSON body of a
`JsonResponse`, with properties that can instead drive the status code or headers.

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
use BYanelli\Roma\Request\Attributes\Rule;
use BYanelli\Roma\Request\ContextualBinding\Request;

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
headers and request metadata, nested objects, response objects, and TypeScript generation.

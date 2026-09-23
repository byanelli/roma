# Changelog

All notable changes to `roma` will be documented in this file.

## Unreleased

- `#[Content]` accessor: maps the request body exactly as sent, for signatures computed over
  the raw bytes.
- Interface- and abstract-class-typed response properties: the generated TypeScript is a
  union of every concrete implementation found in the `roma.typescript.discover`
  directories, each emitted as its own interface — nothing is declared in PHP, so adding
  an implementation and re-running the generator widens the union. With none found the
  contract is emitted as an empty interface. Mapping a request into such a property now
  fails with a clear message instead of an "Unsupported type" error at generation time.
- Planned: a wire discriminator for polymorphic values, so a union of implementations that
  share a shape still narrows in TypeScript.
- Initial development toward the 1.0 release.

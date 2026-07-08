<?php

namespace BYanelli\Roma\TypeScript\Attributes;

use Attribute;

/**
 * TypeScript generation only. An #[Input] property (the default source) reads
 * from both the body and the query string, so it is ambiguous which generated
 * interface it belongs to. This places it in the request's Body interface.
 * Without an explicit mapping attribute an #[Input] property defaults to Body.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class InputMapsToTypeScriptBody {}

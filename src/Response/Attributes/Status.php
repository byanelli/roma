<?php

namespace BYanelli\Roma\Response\Attributes;

use Attribute;

/**
 * Marks the property whose value is the HTTP status code of the JSON response.
 * That property is lifted out of the serialized body. Without it, the response
 * defaults to 200.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Status {}

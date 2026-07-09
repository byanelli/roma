<?php

namespace BYanelli\Roma\Request\Attributes;

use Attribute;

/**
 * Marks a request method to run after the request validates successfully. The
 * method is called through the container, so it may type-hint dependencies
 * (services, the current user, the underlying request) and they are injected.
 * A guard signals rejection by throwing (e.g. a ValidationException or
 * AuthorizationException); its return value is ignored. Multiple guards run in
 * declaration order.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Guard {}

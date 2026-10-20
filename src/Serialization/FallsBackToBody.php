<?php

namespace BYanelli\Roma\Serialization;

/**
 * A destination attribute that is also a request source, such as #[Query]. Under a profile that
 * doesn't allow its destination, the property is written to the body, so a class mapped from a
 * request serializes back to the keys it maps from.
 */
interface FallsBackToBody extends DestinationAttribute {}

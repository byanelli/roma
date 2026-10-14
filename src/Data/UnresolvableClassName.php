<?php

namespace BYanelli\Roma\Data;

use RuntimeException;

/**
 * A class name in a PHPDoc type that is neither fully qualified nor in the declaring class's namespace.
 */
class UnresolvableClassName extends RuntimeException {}

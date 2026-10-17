<?php

namespace BYanelli\Roma\Config;

use Attribute;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Container\ContextualAttribute;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;

/**
 * Marks a config object and sets the config key its properties are read from, such as
 * "services.scrapfly". On a class it lets the container resolve the class by type and lets
 * roma:config:validate find it. On a constructor parameter it sets the prefix for that
 * parameter, overriding the class's, so one class can be used with several prefixes.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PARAMETER)]
final readonly class ConfigObject implements ContextualAttribute
{
    public function __construct(public string $prefix)
    {
        if (trim($prefix) === '') {
            throw new RuntimeException('Roma #[ConfigObject] needs a config key prefix, such as "services.mailgun".');
        }
    }

    /**
     * The class-level prefix of `$class`, or null when it has no #[ConfigObject]. Accepts any
     * container abstract, since it is checked on resolution.
     */
    public static function prefixOf(string $class): ?string
    {
        static $prefixes = [];

        if (array_key_exists($class, $prefixes)) {
            return $prefixes[$class];
        }

        if (! class_exists($class)) {
            return $prefixes[$class] = null;
        }

        $attribute = new ReflectionClass($class)->getAttributes(self::class)[0] ?? null;

        return $prefixes[$class] = $attribute?->newInstance()->prefix;
    }

    /**
     * @throws BindingResolutionException when the parameter isn't typed with a class
     * @throws ConfigValidationException
     */
    public static function resolve(self $attribute, Container $container, ReflectionParameter $parameter): object
    {
        $type = $parameter->getType();
        $class = $type instanceof ReflectionNamedType ? $type->getName() : null;

        if ($class === null || ! class_exists($class)) {
            throw new BindingResolutionException(
                "Roma #[ConfigObject] on \${$parameter->getName()} needs the parameter to be typed with a class."
            );
        }

        return $container->make(ConfigMapper::class)->map($class, $attribute->prefix);
    }
}

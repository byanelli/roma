<?php

namespace BYanelli\Roma\Data;

use BYanelli\Roma\Attributes\AutoDiscriminated;
use BYanelli\Roma\Attributes\Discriminated;
use BYanelli\Roma\Attributes\DiscriminatorValue;
use BYanelli\Roma\Discovery\RomaCache;
use Illuminate\Container\Container;
use ReflectionAttribute;
use ReflectionClass;
use RuntimeException;

/**
 * The key and member classes of a discriminated union, checked against the type it applies to.
 */
final readonly class Discriminator
{
    /**
     * @param  array<string, class-string>  $classes  keyed by discriminator value
     */
    private function __construct(
        public string $key,
        public array $classes,
    ) {}

    /**
     * The union a class-level #[Discriminated] or #[AutoDiscriminated] on an interface or
     * abstract class defines, or null when it has neither.
     *
     * @param  class-string  $contract
     */
    public static function ofContract(string $contract): ?self
    {
        $reflection = new ReflectionClass($contract);
        $listed = ($reflection->getAttributes(Discriminated::class)[0] ?? null)?->newInstance();
        $auto = ($reflection->getAttributes(AutoDiscriminated::class)[0] ?? null)?->newInstance();

        if ($listed !== null && $auto !== null) {
            throw new RuntimeException("Roma {$contract} has both #[Discriminated] and #[AutoDiscriminated]. Remove one of them.");
        }

        return match (true) {
            $listed !== null => self::fromAttribute($listed, $contract, $contract),
            $auto !== null => self::discovered($auto, $contract),
            default => null,
        };
    }

    /**
     * @param  ReflectionClass<object>  $class
     */
    public static function assertNotOnConcreteClass(ReflectionClass $class): void
    {
        if ($class->isInterface() || $class->isAbstract()) {
            return;
        }

        if ($class->getAttributes(Discriminated::class) !== []) {
            throw new RuntimeException(
                "Roma #[Discriminated] on {$class->getName()} applies only to an interface or abstract class, or to a "
                .'property. Move it to the interface or abstract class its members share, or to the property.'
            );
        }

        if ($class->getAttributes(AutoDiscriminated::class) !== []) {
            throw new RuntimeException(
                "Roma #[AutoDiscriminated] on {$class->getName()} applies only to an interface or abstract class. "
                .'Move it to the interface or abstract class its members share.'
            );
        }
    }

    /**
     * @param  class-string  $contract
     */
    private static function discovered(AutoDiscriminated $attribute, string $contract): self
    {
        $cache = Container::getInstance()->make(RomaCache::class);
        $hint = $cache->isCached()
            ? " The members are read from {$cache->cachePath()}; if you added or removed an implementation, run php artisan roma:cache."
            : '';
        $classes = $cache->membersOf($contract);

        if ($classes === []) {
            throw new RuntimeException(
                "Roma #[AutoDiscriminated] on {$contract} found no concrete class that extends or implements it "
                ."in the roma.discover directories.{$hint}"
            );
        }

        try {
            return self::fromAttribute(new Discriminated($classes, $attribute->key), $contract, $contract, attributeName: 'AutoDiscriminated');
        } catch (RuntimeException $e) {
            throw new RuntimeException($e->getMessage().$hint, previous: $e);
        }
    }

    /**
     * `$printableName` is the property or contract the attribute is on, for error messages.
     *
     * @param  ?class-string  $contract  the interface or abstract class the members must extend
     * @param  ?list<class-string>  $union  the members of a native union type, which the list must equal
     */
    public static function fromAttribute(
        Discriminated $attribute,
        string $printableName,
        ?string $contract = null,
        ?array $union = null,
        string $attributeName = 'Discriminated',
    ): self {
        $prefix = "Roma #[{$attributeName}] on {$printableName}";

        if ($attribute->key === '') {
            throw new RuntimeException("{$prefix} has an empty key.");
        }

        // Reserved, so that a dotted discriminator key can nest later as #[Key] does.
        if (str_contains($attribute->key, '.')) {
            throw new RuntimeException("{$prefix} has the key \"{$attribute->key}\". A discriminator key is a key at the top of the object and may not contain a dot.");
        }

        if ($attribute->classes === []) {
            throw new RuntimeException("{$prefix} lists no classes.");
        }

        if ($union !== null && ! self::sameClasses($attribute->classes, $union)) {
            throw new RuntimeException(
                "{$prefix} must list exactly the classes of the union type ".implode('|', $union).'.'
            );
        }

        $classes = [];

        foreach ($attribute->classes as $class) {
            if (! class_exists($class) || self::isAbstractOrEnum($class)) {
                throw new RuntimeException("{$prefix} lists {$class}, which is not a concrete class.");
            }

            if ($contract !== null && ! is_a($class, $contract, true)) {
                throw new RuntimeException("{$prefix} lists {$class}, which does not extend or implement {$contract}.");
            }

            $value = self::valueOf($class);

            if (isset($classes[$value])) {
                throw new RuntimeException(
                    "{$prefix}: {$classes[$value]} and {$class} both have the discriminator value \"{$value}\". "
                    .'Give one of them a different value with #[DiscriminatorValue].'
                );
            }

            $classes[$value] = $class;
        }

        return new self($attribute->key, $classes);
    }

    /**
     * @param  class-string  $class
     */
    private static function valueOf(string $class): string
    {
        $reflection = new ReflectionClass($class);
        $attribute = $reflection->getAttributes(DiscriminatorValue::class, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;

        return $attribute?->newInstance()->value ?? $reflection->getShortName();
    }

    /**
     * @param  class-string  $class
     */
    private static function isAbstractOrEnum(string $class): bool
    {
        $reflection = new ReflectionClass($class);

        return $reflection->isAbstract() || $reflection->isEnum();
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function sameClasses(array $a, array $b): bool
    {
        $normalize = fn (array $classes) => collect($classes)->map(fn (string $c) => strtolower(ltrim($c, '\\')))->unique()->sort()->values()->all();

        return count($a) === count($b) && $normalize($a) === $normalize($b);
    }

    /**
     * The discriminator value of an instance, or null when its class is not a member.
     */
    public function valueFor(object $value): ?string
    {
        $found = array_search($value::class, $this->classes, true);

        return $found === false ? null : (string) $found;
    }

    /**
     * @return list<string>
     */
    public function values(): array
    {
        return array_map(strval(...), array_keys($this->classes));
    }
}

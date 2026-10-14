<?php

namespace BYanelli\Roma\Data;

use BYanelli\Roma\Attributes\Discriminated;
use BYanelli\Roma\Attributes\DiscriminatorValue;
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
     * The class-level attribute of an interface or abstract class.
     *
     * @param  class-string  $contract
     */
    public static function attributeOf(string $contract): ?Discriminated
    {
        $attribute = new ReflectionClass($contract)->getAttributes(Discriminated::class)[0] ?? null;

        return $attribute?->newInstance();
    }

    /**
     * `$subject` describes where the attribute applies, for error messages.
     *
     * @param  ?class-string  $contract  the interface or abstract class every member must extend
     * @param  ?list<class-string>  $union  the members of a native union type, which the list must equal
     */
    public static function fromAttribute(
        Discriminated $attribute,
        string $subject,
        ?string $contract = null,
        ?array $union = null,
    ): self {
        $prefix = "Roma #[Discriminated] on {$subject}";

        if ($attribute->key === '') {
            throw new RuntimeException("{$prefix} has an empty key.");
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

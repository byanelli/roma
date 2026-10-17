<?php

namespace BYanelli\Roma\Config;

use BYanelli\Roma\Data\ClassDefinitionBuilder;
use BYanelli\Roma\Data\KeyPath;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Data\ObjectMapper;
use BYanelli\Roma\Data\Types\Class_;
use Illuminate\Contracts\Config\Repository;
use RuntimeException;
use SensitiveParameter;

/**
 * Maps config objects from the config repository. Use Facades\Mapper::fromConfig(), or
 * resolve the class from the container.
 */
final readonly class ConfigMapper
{
    public function __construct(
        private ObjectMapper $mapper,
        private Repository $config,
    ) {}

    /**
     * @template T of object
     *
     * @param  class-string<T>  $className
     * @param  ?string  $prefix  overrides the class's #[ConfigObject] prefix
     * @return T
     *
     * @throws ConfigValidationException
     * @throws \ReflectionException
     */
    public function map(string $className, ?string $prefix = null): object
    {
        $prefix ??= ConfigObject::prefixOf($className) ?? throw new RuntimeException(
            "Roma can't map {$className} from config: it has no #[ConfigObject] prefix. "
            .'Add #[ConfigObject(\'...\')] to the class, or pass a prefix.'
        );

        $policy = new ConfigPolicy($prefix);
        $mapping = $this->mapper->mapping($className, $this->config, $policy);

        try {
            $this->mapper->validateMapping($mapping);
        } catch (MappingValidationException $e) {
            $class = new ClassDefinitionBuilder($policy)->buildClassDefinition($className);

            // Not chained to $e, whose messages may contain the values.
            throw new ConfigValidationException($className, $this->redact(
                $e->errors(),
                $this->sensitiveValues($class, $mapping->toArray(), KeyPath::empty()),
            ));
        }

        /** @var T */
        return $this->mapper->build($mapping);
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @param  list<string>  $values
     * @return array<string, list<string>>
     */
    private function redact(array $errors, array $values): array
    {
        if ($values === []) {
            return $errors;
        }

        // Longest first, so a value that contains another is replaced whole.
        usort($values, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return array_map(
            fn (array $messages) => array_map(fn (string $message) => str_replace($values, '[redacted]', $message), $messages),
            $errors,
        );
    }

    /**
     * The values of the #[\SensitiveParameter] parameters of `$class` and of its nested objects,
     * as strings.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    private function sensitiveValues(Class_ $class, array $data, KeyPath $base): array
    {
        $values = [];

        foreach ($class->properties as $property) {
            $path = $base->concat($property->fullKey());
            $value = $path->getFrom($data);

            if ($property->hasAttribute(SensitiveParameter::class)) {
                $values = [...$values, ...$this->strings($value)];
            } elseif ($property->type instanceof Class_ && is_array($value)) {
                $values = [...$values, ...$this->sensitiveValues($property->type, $data, $path)];
            }
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        return match (true) {
            is_array($value) => array_merge([], ...array_map($this->strings(...), array_values($value))),
            is_string($value), is_int($value), is_float($value) => (string) $value === '' ? [] : [(string) $value],
            default => [],
        };
    }
}

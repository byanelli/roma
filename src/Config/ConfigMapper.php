<?php

namespace BYanelli\Roma\Config;

use BYanelli\Roma\Data\ClassDefinitionBuilder;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Data\ObjectMapper;
use BYanelli\Roma\Data\Operation;
use BYanelli\Roma\Data\SensitiveValues;
use BYanelli\Roma\Data\ValidatedObjects;
use Illuminate\Contracts\Config\Repository;
use ReflectionClass;
use RuntimeException;

/**
 * Maps config objects from the config repository. Use Facades\Mapper::fromConfig(), or
 * resolve the class from the container, which returns a lazy proxy.
 */
final readonly class ConfigMapper
{
    public function __construct(
        private ObjectMapper $mapper,
        private Repository $config,
        private ValidatedObjects $validatedObjects,
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
            throw new ConfigValidationException($className, SensitiveValues::redactErrors(
                $e->errors(),
                SensitiveValues::inMapping($class, $mapping->toArray()),
            ));
        }

        /** @var T */
        return $this->mapper->build($mapping);
    }

    /**
     * A lazy proxy that maps the object on first access, so the config is read and validated
     * then. PHP leaves a proxy whose initializer threw uninitialized, so the next access maps
     * it again. The proxy is recorded as validated along with the mapped object.
     *
     * @template T of object
     *
     * @param  class-string<T>  $className
     * @param  ?string  $prefix  overrides the class's #[ConfigObject] prefix
     * @return T
     *
     * @throws \ReflectionException
     */
    public function lazy(string $className, ?string $prefix = null): object
    {
        return new ReflectionClass($className)->newLazyProxy(function (object $proxy) use ($className, $prefix): object {
            $object = $this->map($className, $prefix);
            $this->validatedObjects->record($proxy, Operation::None);

            return $object;
        });
    }
}

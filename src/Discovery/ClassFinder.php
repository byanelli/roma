<?php

namespace BYanelli\Roma\Discovery;

use ReflectionClass;
use Spatie\StructureDiscoverer\Discover;

/**
 * Finds the concrete classes declared under a set of directories.
 */
class ClassFinder
{
    /**
     * @param  list<string>  $paths  paths that are not directories are ignored
     * @return list<class-string>
     */
    public function concreteClasses(array $paths): array
    {
        $directories = array_values(array_filter($paths, 'is_dir'));

        if ($directories === []) {
            return [];
        }

        /** @var list<class-string> $classes */
        $classes = Discover::in(...$directories)->classes()->get();

        return array_values(array_filter($classes, $this->isConcrete(...)));
    }

    /**
     * @param  class-string  $class
     */
    private function isConcrete(string $class): bool
    {
        $reflection = new ReflectionClass($class);

        return ! $reflection->isAbstract()
            && ! $reflection->isInterface()
            && ! $reflection->isEnum();
    }
}

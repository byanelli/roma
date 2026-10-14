<?php

namespace BYanelli\Roma\Data;

use BYanelli\Roma\Data\Types\Class_;
use Closure;
use ReflectionClass;

/**
 * The nested class definitions of one top-level build. A class that contains itself, directly
 * or indirectly, gets a lazy reference to its own definition, which is complete once the build is.
 *
 * @internal
 */
final class NestedDefinitions
{
    /**
     * @var array<class-string, Class_>
     */
    private array $built = [];

    /**
     * @var array<class-string, Class_>
     */
    private array $references = [];

    /**
     * @var array<class-string, list<Closure(Class_): void>>
     */
    private array $whenBuilt = [];

    /**
     * @param  class-string  $class
     * @param  Closure(): Class_  $build
     */
    public function get(string $class, Closure $build): Class_
    {
        if (isset($this->built[$class])) {
            return $this->built[$class];
        }

        if (array_key_exists($class, $this->references)) {
            return $this->references[$class];
        }

        $this->references[$class] = $this->reference($class);

        $definition = $this->built[$class] = $build();

        foreach ($this->whenBuilt[$class] ?? [] as $callback) {
            $callback($definition);
        }

        unset($this->whenBuilt[$class]);

        return $definition;
    }

    /**
     * Runs `$callback` with the class's definition now, or when it is complete if it is being built.
     *
     * @param  class-string  $class
     * @param  Closure(Class_): void  $callback
     */
    public function whenBuilt(string $class, Closure $callback): void
    {
        if (isset($this->built[$class])) {
            $callback($this->built[$class]);
        } else {
            $this->whenBuilt[$class][] = $callback;
        }
    }

    /**
     * A lazy ghost: reading it before the build is complete would fail.
     *
     * @param  class-string  $class
     */
    private function reference(string $class): Class_
    {
        return new ReflectionClass(Class_::class)->newLazyGhost(function (Class_ $ghost) use ($class): void {
            $definition = $this->built[$class];

            $ghost->__construct($definition->class, $definition->properties);
        });
    }
}

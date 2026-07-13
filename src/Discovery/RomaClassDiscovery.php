<?php

namespace BYanelli\Roma\Discovery;

use BYanelli\Roma\Request\ContextualBinding\Request;
use BYanelli\Roma\Response\IsResponsable;
use BYanelli\Roma\Response\Response;
use ReflectionClass;

/**
 * Classifies the classes under a set of directories into Roma requests and
 * responses, so the TypeScript generator does not need them listed by hand.
 *
 * A request is any concrete class carrying a class-level #[Request] attribute.
 * A response is any concrete class extending Response or using IsResponsable.
 */
class RomaClassDiscovery
{
    public function __construct(
        private ClassFinder $finder = new ClassFinder,
    ) {}

    /**
     * @param  list<string>  $paths
     */
    public function discover(array $paths): DiscoveredClasses
    {
        $requests = [];
        $responses = [];

        foreach ($this->finder->classesIn($paths) as $class) {
            if (! $this->isConcrete($class)) {
                continue;
            }

            if ($this->isRequest($class)) {
                $requests[] = $class;
            } elseif ($this->isResponse($class)) {
                $responses[] = $class;
            }
        }

        return new DiscoveredClasses($requests, $responses);
    }

    /**
     * @param  class-string  $class
     */
    public function isRequest(string $class): bool
    {
        return Request::isMarkedOn($class);
    }

    /**
     * @param  class-string  $class
     */
    public function isResponse(string $class): bool
    {
        return is_a($class, Response::class, true)
            || in_array(IsResponsable::class, $this->traitsUsedBy($class), true);
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

    /**
     * Every trait used by the class, its parents, and any of those traits —
     * equivalent to Laravel's class_uses_recursive, reimplemented so discovery
     * does not lean on a framework helper.
     *
     * @param  class-string  $class
     * @return list<class-string>
     */
    private function traitsUsedBy(string $class): array
    {
        $traits = [];

        for ($current = $class; $current !== false; $current = get_parent_class($current)) {
            foreach (class_uses($current) ?: [] as $trait) {
                $traits[$trait] = true;
            }
        }

        $queue = array_keys($traits);

        while ($queue !== []) {
            foreach (class_uses(array_pop($queue)) ?: [] as $trait) {
                if (! isset($traits[$trait])) {
                    $traits[$trait] = true;
                    $queue[] = $trait;
                }
            }
        }

        /** @var list<class-string> */
        return array_keys($traits);
    }
}

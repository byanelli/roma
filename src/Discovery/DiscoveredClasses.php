<?php

namespace BYanelli\Roma\Discovery;

use ReflectionClass;

/**
 * The result of scanning directories for Roma classes: the request classes
 * (marked with a class-level #[Request]), the response classes (extending
 * Response or using the IsResponsable trait), and every concrete class the scan
 * saw — each as autoloadable class-strings.
 *
 * The full class list is what makes an interface-typed property describable:
 * the generator asks it which concrete classes satisfy a contract rather than
 * making the author list them.
 */
readonly class DiscoveredClasses
{
    /**
     * @param  list<class-string>  $requests
     * @param  list<class-string>  $responses
     * @param  list<class-string>  $classes
     */
    public function __construct(
        public array $requests = [],
        public array $responses = [],
        public array $classes = [],
    ) {}

    /**
     * The discovered concrete classes that satisfy a contract — an interface or
     * an abstract class — sorted by name so the generated file is stable.
     *
     * Only the scanned directories are searched: an implementation living
     * outside them is simply not found, and the contract is described by
     * whatever was.
     *
     * @param  class-string  $contract
     * @return list<class-string>
     */
    public function implementationsOf(string $contract): array
    {
        $implementations = [];

        foreach ($this->classes as $class) {
            if ($class === $contract || ! is_a($class, $contract, true)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            $implementations[] = $class;
        }

        sort($implementations);

        return $implementations;
    }
}

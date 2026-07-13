<?php

namespace BYanelli\Roma\Discovery;

/**
 * The result of scanning directories for Roma classes: the request classes
 * (marked with a class-level #[Request]) and the response classes (extending
 * Response or using the IsResponsable trait), each as autoloadable class-strings.
 */
readonly class DiscoveredClasses
{
    /**
     * @param  list<class-string>  $requests
     * @param  list<class-string>  $responses
     */
    public function __construct(
        public array $requests = [],
        public array $responses = [],
    ) {}
}

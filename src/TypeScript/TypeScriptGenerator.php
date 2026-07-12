<?php

namespace BYanelli\Roma\TypeScript;

use BYanelli\Roma\Request\Data\ClassDefinitionBuilder as PhpClassDefinitionBuilder;
use BYanelli\Roma\Request\Data\Property as PhpProperty;
use BYanelli\Roma\Request\Data\Sources;
use BYanelli\Roma\Response\Attributes\Header as ResponseHeader;
use BYanelli\Roma\Response\Attributes\Optional as ResponseOptional;
use BYanelli\Roma\Response\Attributes\Status as ResponseStatus;
use BYanelli\Roma\TypeScript\Attributes\InputMapsToTypeScriptQuery;
use BYanelli\Roma\TypeScript\Attributes\TypeScriptName;
use BYanelli\Roma\TypeScript\Types\Array_;
use BYanelli\Roma\TypeScript\Types\Enum;
use BYanelli\Roma\TypeScript\Types\Interface_;

/**
 * Emits TypeScript definitions for Roma request and response objects by walking
 * the immutable Class_/Property/Type IR that ClassDefinitionBuilder produces.
 *
 * A request maps to up to three interfaces — {Name}Body, {Name}Query and
 * {Name}Headers — because its properties are sourced from different parts of the
 * HTTP request. A response maps to a single interface. In both directions fields
 * are keyed by their wire key (the request source key / the response #[Key] or
 * property name), not the PHP property name.
 */
readonly class TypeScriptGenerator
{
    /**
     * @param  list<class-string>  $requests
     * @param  list<class-string>  $responses
     */
    public function __construct(
        private array $requests = [],
        private array $responses = [],
        private TypeScriptRenderer $renderer = new TypeScriptRenderer,
    ) {}

    public function generate(): string
    {
        $interfaces = $this->collectInterfaces();

        // Enums referenced by any interface are emitted as their own named types,
        // ahead of the interfaces that reference them.
        $blocks = [
            ...array_map($this->renderer->renderEnum(...), $this->collectEnums($interfaces)),
            ...array_map($this->renderer->renderInterface(...), $interfaces),
        ];

        return "// This file is auto-generated. Do not edit by hand.\n\n"
            .implode("\n\n", $blocks)."\n";
    }

    /**
     * The distinct enums referenced (directly or through arrays) by any emitted
     * interface, ordered by their TypeScript name.
     *
     * @param  list<Interface_>  $interfaces
     * @return list<class-string<\UnitEnum>>
     */
    private function collectEnums(array $interfaces): array
    {
        $classes = [];

        foreach ($interfaces as $interface) {
            foreach ($interface->properties as $property) {
                $this->collectEnumsFromType($property->type, $classes);
            }
        }

        return collect($classes)
            ->unique()
            ->sortBy(fn (string $class) => TypeScriptName::for($class))
            ->values()
            ->all();
    }

    /**
     * @param  list<class-string<\UnitEnum>>  $classes
     */
    private function collectEnumsFromType(mixed $type, array &$classes): void
    {
        if ($type instanceof Enum) {
            $classes[] = $type->class;
        } elseif ($type instanceof Array_) {
            $this->collectEnumsFromType($type->memberType, $classes);
        }
    }

    /**
     * @return list<Interface_>
     */
    private function collectInterfaces(): array
    {
        $phpClassDefinitionBuilder = new PhpClassDefinitionBuilder;
        $tsInterfaceBuilder = new InterfaceBuilder;

        $interfaces = [];

        foreach ($this->requests as $request) {
            $phpClass = $phpClassDefinitionBuilder->buildClassDefinition($request);

            // A request is split into one interface per HTTP location its
            // properties come from; empty buckets are dropped downstream. A
            // request field is optional when it is not required (has a default
            // or is nullable-defaulted).
            foreach ([Bucket::Body, Bucket::Query, Bucket::Headers] as $bucket) {
                $interfaces[] = $tsInterfaceBuilder->buildInterface(
                    $phpClass,
                    isPropertyOptional: fn (PhpProperty $property) => ! $property->isRequired,
                    includeProperty: fn (PhpProperty $property) => $this->getBucket($property) === $bucket,
                    name: TypeScriptName::for($request).$bucket->name,
                    stringValued: $bucket === Bucket::Headers,
                );
            }
        }

        foreach ($this->responses as $response) {
            $phpClass = $phpClassDefinitionBuilder->buildClassDefinition($response);

            foreach ([Bucket::Body, Bucket::Headers] as $bucket) {
                if ($bucket === Bucket::Body) {
                    // The body is everything not lifted elsewhere: #[Status] becomes the
                    // HTTP status code and #[Header] properties become response headers,
                    // so both are dropped from the body.
                    $includeProperty = fn (PhpProperty $property) => ! $property->hasAttribute(ResponseStatus::class)
                        && ! $property->hasAttribute(ResponseHeader::class);
                } else {
                    // The response headers are their own interface, keyed by header
                    // name — mirroring a request's Headers interface. Dropped if empty.
                    $includeProperty = fn (PhpProperty $property) => $property->hasAttribute(ResponseHeader::class);
                }

                $interfaces[] = $tsInterfaceBuilder->buildInterface(
                    $phpClass,
                    // A response field is optional only when marked #[Optional].
                    isPropertyOptional: fn (PhpProperty $property) => $property->hasAttribute(ResponseOptional::class),
                    includeProperty: $includeProperty,
                    name: TypeScriptName::for($response).$bucket->name,
                    stringValued: $bucket === Bucket::Headers,
                );
            }
        }

        // Remove empty interfaces, flatten nested interfaces into the top
        // level, deduplicate and sort by name.
        $collected = collect($interfaces)
            ->filter(fn (Interface_ $i) => ! $i->isEmpty)
            ->flatMap(fn (Interface_ $i) => $i->flatten())
            ->unique(fn (Interface_ $i) => $i->uniqueKey)
            ->sortBy(fn (Interface_ $i) => $i->name)
            ->all();

        return array_values($collected);
    }

    /**
     * The request interface bucket a property belongs to — Body, Query or
     * Headers — or null when the property is not sent in the JSON payload (a
     * cookie, route parameter, file upload or nested request object).
     */
    private function getBucket(PhpProperty $property): ?Bucket
    {
        return match ($property->getRootSource()::class) {
            Sources\Body::class => Bucket::Body,
            Sources\Query::class => Bucket::Query,
            Sources\Header::class => Bucket::Headers,
            // #[Input] reads body ∪ query, so it is ambiguous: it maps to Body
            // by default, or to Query when annotated to move there.
            Sources\Input::class => $property->hasAttribute(InputMapsToTypeScriptQuery::class)
                ? Bucket::Query
                : Bucket::Body,
            default => null,
        };
    }
}

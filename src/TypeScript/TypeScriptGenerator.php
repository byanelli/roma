<?php

namespace BYanelli\Roma\TypeScript;

use BYanelli\Roma\Request\Data\ClassDefinitionBuilder as PhpClassDefinitionBuilder;
use BYanelli\Roma\Request\Data\Property as PhpProperty;
use BYanelli\Roma\Request\Data\Sources;
use BYanelli\Roma\Response\Attributes\Header as ResponseHeader;
use BYanelli\Roma\Response\Attributes\Optional as ResponseOptional;
use BYanelli\Roma\Response\Attributes\Status as ResponseStatus;
use BYanelli\Roma\TypeScript\Attributes\InputMapsToTypeScriptQuery;
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

        $bodies = array_map($this->renderer->renderInterface(...), $interfaces);

        return "// This file is auto-generated. Do not edit by hand.\n\n"
            .implode("\n\n", $bodies)."\n";
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
            foreach (RequestBucket::cases() as $bucket) {
                $interfaces[] = $tsInterfaceBuilder->buildInterface(
                    $phpClass,
                    isPropertyOptional: fn (PhpProperty $property) => ! $property->isRequired,
                    includeProperty: fn (PhpProperty $property) => $this->requestBucket($property) === $bucket,
                    name: class_basename($request).$bucket->name,
                );
            }
        }

        foreach ($this->responses as $response) {
            $phpClass = $phpClassDefinitionBuilder->buildClassDefinition($response);

            // A response is a single interface; there is no source split to
            // make. A response field is optional only when marked #[Optional],
            // and #[Status] / #[Header] fields are lifted out of the body.
            $interfaces[] = $tsInterfaceBuilder->buildInterface(
                $phpClass,
                isPropertyOptional: fn (PhpProperty $property) => $property->hasAttribute(ResponseOptional::class),
                includeProperty: fn (PhpProperty $property) => ! $property->hasAttribute(ResponseStatus::class)
                    && ! $property->hasAttribute(ResponseHeader::class),
                name: class_basename($response),
            );
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
    private function requestBucket(PhpProperty $property): ?RequestBucket
    {
        return match ($property->getRootSource()::class) {
            Sources\Body::class => RequestBucket::Body,
            Sources\Query::class => RequestBucket::Query,
            Sources\Header::class => RequestBucket::Headers,
            // #[Input] reads body ∪ query, so it is ambiguous: it maps to Body
            // by default, or to Query when annotated to move there.
            Sources\Input::class => $property->hasAttribute(InputMapsToTypeScriptQuery::class)
                ? RequestBucket::Query
                : RequestBucket::Body,
            default => null,
        };
    }
}

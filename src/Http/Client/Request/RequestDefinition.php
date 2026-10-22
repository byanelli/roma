<?php

namespace BYanelli\Roma\Http\Client\Request;

use BYanelli\Roma\Data\Attributes\ExplicitKeyAttribute;
use BYanelli\Roma\Data\Attributes\KeyAttribute;
use BYanelli\Roma\Data\Reflection\ReflectionParamOrProperty;
use BYanelli\Roma\Http\Client\Attributes\AsForm;
use BYanelli\Roma\Http\Client\Attributes\AsMultipart;
use BYanelli\Roma\Http\Client\Attributes\Endpoint;
use BYanelli\Roma\Http\Client\Attributes\Responses;
use BYanelli\Roma\Http\Enums\Method;
use BYanelli\Roma\Serialization\Destination;
use BYanelli\Roma\Serialization\DestinationAttribute;
use BYanelli\Roma\Serialization\Serialized;
use GuzzleHttp\Psr7\Utils;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

/**
 * Where the properties of a client request class are sent: its method and path, and each
 * property's destination. Built once per class.
 *
 * @internal
 */
final class RequestDefinition
{
    /**
     * @var array<class-string, self>
     */
    private static array $cache = [];

    /**
     * @param  class-string  $class
     * @param  list<string>  $pathParameters  the parameter names in the path template
     * @param  list<string>  $boundByName  properties without a destination attribute that fill the path parameter of their name
     * @param  array<string, string>  $files  the multipart field name of each file property
     * @param  'json'|'form_params'|'multipart'  $bodyFormat  Laravel's name for the body encoding
     * @param  array<string, list<class-string>>  $responses  the #[Responses] of the class, then of its parents, keyed by where each is declared
     */
    private function __construct(
        public readonly string $class,
        public readonly Method $method,
        public readonly ?string $path,
        public readonly array $pathParameters,
        public readonly array $boundByName,
        public readonly array $files,
        public readonly string $bodyFormat,
        public readonly bool $hasBody,
        public readonly array $responses,
    ) {}

    /**
     * @param  class-string  $class
     */
    public static function of(string $class): self
    {
        return self::$cache[$class] ??= self::build(new ReflectionClass($class));
    }

    /**
     * The URL, the values for each destination and the files to attach.
     *
     * @param  ?string  $url  the URL passed to sendRequest(), which takes precedence
     */
    public function outgoing(object $request, Serialized $serialized, ?string $url): OutgoingRequest
    {
        $body = $serialized->body;
        $path = $serialized->path;

        foreach ($this->boundByName as $name) {
            if (array_key_exists($name, $body)) {
                $path[$name] = $body[$name];
                unset($body[$name]);
            }
        }

        $url ??= $serialized->url ?? $this->path ?? throw new RuntimeException(
            "Roma request {$this->shortName()} has no URL. Give its method attribute a path, set its #[Url] property, or pass a URL to sendRequest()."
        );

        if ($url === $this->path) {
            foreach ($this->pathParameters as $name) {
                if (! isset($path[$name])) {
                    throw new RuntimeException("Roma request {$this->shortName()} has no value for the path parameter {{$name}}.");
                }
            }
        }

        if ($this->bodyFormat === 'multipart') {
            $body = $this->multipart($request, $body);
        }

        return new OutgoingRequest($url, $serialized->query, $body, $serialized->headers, $path);
    }

    /**
     * @param  ReflectionClass<object>  $class
     */
    private static function build(ReflectionClass $class): self
    {
        $name = $class->getShortName();

        $endpoint = ($class->getAttributes(Endpoint::class, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null)?->newInstance()
            ?? throw new RuntimeException("Roma request {$name} has no method attribute. Add one such as #[Get('/path')] or #[Post('/path')].");

        $method = $endpoint->method();
        $template = $endpoint->path === null ? [] : self::templateParameters($endpoint->path);
        $form = $class->getAttributes(AsForm::class) !== [];
        $multipart = $class->getAttributes(AsMultipart::class) !== [];

        if ($form && $multipart) {
            throw new RuntimeException("Roma request {$name} has both #[AsForm] and #[AsMultipart]. Remove one of them.");
        }

        $boundByName = $pathKeys = $bodyProperties = $files = [];

        foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $prop = new ReflectionParamOrProperty($property, instantiate: [DestinationAttribute::class, ExplicitKeyAttribute::class, KeyAttribute::class]);
            $attributes = $prop->attributes;
            $destination = $attributes->destinationAttribute()?->destination();
            $key = $attributes->explicitKey() ?? $prop->defaultKey;

            if ($destination === null && in_array($key, $template, true) && $attributes->declaredKey() === null) {
                $boundByName[] = $key;
                $pathKeys[] = $key;

                continue;
            }

            match ($destination) {
                null => throw new RuntimeException(
                    "Roma request property \"{$prop->printableName}\" has no destination. Add #[Body], #[Query], #[Header] or #[Path]"
                    .($template !== [] ? ', or name it after a path parameter.' : '.')
                ),
                Destination::Status => throw new RuntimeException(
                    "Roma request property \"{$prop->printableName}\" has #[Status], but a request has no status. Remove it."
                ),
                Destination::Path => $pathKeys[] = $key,
                Destination::Body => $bodyProperties[] = $prop->name,
                default => null,
            };

            if (self::isFile($prop)) {
                if ($destination !== Destination::Body) {
                    throw new RuntimeException("Roma request property \"{$prop->printableName}\" is a file, which can only be sent in the body. Add #[Body].");
                }

                $files[$prop->name] = $key;
            }
        }

        if ($bodyProperties !== [] && in_array($method, [Method::Get, Method::Head], true)) {
            throw new RuntimeException(
                "Roma request {$name} is sent with {$method->value}, which has no body, but \${$bodyProperties[0]} has #[Body]. Use #[Query], or another method."
            );
        }

        if ($form && $files !== []) {
            throw new RuntimeException("Roma request {$name} has #[AsForm] and a file property. Files are sent as multipart, so remove #[AsForm].");
        }

        if ($endpoint->path !== null) {
            foreach (array_diff($template, $pathKeys) as $parameter) {
                throw new RuntimeException(
                    "Roma request {$name} has no property for the path parameter {{$parameter}}. Add a property named {$parameter}, or #[Path('{$parameter}')] on one."
                );
            }

            foreach (array_diff($pathKeys, $template) as $key) {
                throw new RuntimeException("Roma request {$name} has a #[Path] property for \"{$key}\", but its path has no {{$key}}.");
            }
        }

        return new self(
            class: $class->getName(),
            method: $method,
            path: $endpoint->path,
            pathParameters: $template,
            boundByName: $boundByName,
            files: $files,
            bodyFormat: match (true) {
                $multipart || $files !== [] => 'multipart',
                $form => 'form_params',
                default => 'json',
            },
            hasBody: ! in_array($method, [Method::Get, Method::Head], true),
            responses: self::responses($class),
        );
    }

    /**
     * The names in a URI template such as "/users/{id}/posts{?page,per_page}".
     *
     * @return list<string>
     */
    private static function templateParameters(string $path): array
    {
        preg_match_all('/\{([^}]*)\}/', $path, $matches);

        $names = [];

        foreach ($matches[1] as $expression) {
            foreach (explode(',', ltrim($expression, '+#./;?&')) as $variable) {
                $names[] = (string) preg_replace('/(\*|:\d+)$/', '', trim($variable));
            }
        }

        return array_values(array_unique($names));
    }

    private static function isFile(ReflectionParamOrProperty $prop): bool
    {
        $names = $prop->typeName !== null ? [$prop->typeName] : $prop->unionMemberNames;

        return array_any($names, fn (string $type) => is_a($type, File::class, true));
    }

    /**
     * PHP doesn't return a parent's attributes with the class's, so the parents are read too.
     *
     * @param  ReflectionClass<object>  $class
     * @return array<string, list<class-string>>
     */
    private static function responses(ReflectionClass $class): array
    {
        $lists = [];

        for ($current = $class; $current !== false; $current = $current->getParentClass()) {
            foreach ($current->getAttributes(Responses::class) as $attribute) {
                $lists["#[Responses] on {$current->getShortName()}"] = $attribute->newInstance()->classes;
            }
        }

        return $lists;
    }

    /**
     * Multipart parts: nested arrays become bracketed names ("tags[0]"), and file properties
     * are attached with their contents.
     *
     * @param  array<string, mixed>  $body
     * @return list<array{name: string, contents: mixed, filename?: string}>
     */
    private function multipart(object $request, array $body): array
    {
        $files = [];

        foreach ($this->files as $property => $name) {
            unset($body[$name]);

            $reflection = new ReflectionProperty($this->class, $property);
            $file = $reflection->isInitialized($request) ? $reflection->getValue($request) : null;

            if ($file instanceof File) {
                $files[] = [
                    'name' => $name,
                    'contents' => Utils::tryFopen($file->getPathname(), 'r'),
                    'filename' => $file instanceof SymfonyUploadedFile ? $file->getClientOriginalName() : $file->getFilename(),
                ];
            }
        }

        return [...$this->parts($body, ''), ...$files];
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<array{name: string, contents: mixed}>
     */
    private function parts(array $values, string $prefix): array
    {
        $parts = [];

        foreach ($values as $key => $value) {
            $name = $prefix === '' ? (string) $key : "{$prefix}[{$key}]";

            if (is_array($value)) {
                $parts = [...$parts, ...$this->parts($value, $name)];
            } elseif ($value !== null) {
                // As in a form body, which http_build_query() encodes.
                $parts[] = ['name' => $name, 'contents' => is_bool($value) ? ($value ? '1' : '0') : $value];
            }
        }

        return $parts;
    }

    private function shortName(): string
    {
        return class_basename($this->class);
    }
}

<?php

namespace BYanelli\Roma\Data;

use InvalidArgumentException;

/**
 * A position within a source's data or a nested object, as a list of array keys:
 * `['address', 'city']`, or `['items', '*', 'code']` in a rule key. A segment is one array
 * key and may contain a literal dot, so the data is walked one segment at a time and never
 * split on '.'.
 */
final readonly class KeyPath
{
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Splits a key on dots, as Laravel does: "error.message" is `['error', 'message']`, and an
     * escaped dot is literal, so "created\.at" is `['created.at']`.
     *
     * @throws InvalidArgumentException when a segment is empty, as in "a..b"
     */
    public static function fromDotted(string $key): self
    {
        $segments = array_map(
            fn (string $segment) => str_replace('\\.', '.', $segment),
            preg_split('/(?<!\\\\)\./', $key) ?: [$key],
        );

        if (in_array('', $segments, true)) {
            throw new InvalidArgumentException("The key \"{$key}\" has an empty segment.");
        }

        return new self($segments);
    }

    /**
     * The indexes of the first two paths that can't both be in one array: two paths with the
     * same key, or one path inside the other's value. The key comparison joins the segments,
     * so `['a.b']` and `['a', 'b']`, which would share an error key, conflict too.
     *
     * @param  list<self>  $paths
     * @return ?array{int, int}
     */
    public static function firstConflict(array $paths): ?array
    {
        foreach ($paths as $j => $path) {
            for ($i = 0; $i < $j; $i++) {
                if ($paths[$i]->toKey() === $path->toKey() || $paths[$i]->isPrefixOf($path) || $path->isPrefixOf($paths[$i])) {
                    return [$i, $j];
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $segments
     */
    public function __construct(public array $segments = []) {}

    public function append(string ...$segments): self
    {
        return new self([...$this->segments, ...array_values($segments)]);
    }

    public function concat(self $path): self
    {
        return new self([...$this->segments, ...$path->segments]);
    }

    public function isEmpty(): bool
    {
        return $this->segments === [];
    }

    /**
     * Whether `$path` is inside this path's value: this path is shorter and starts it.
     */
    public function isPrefixOf(self $path): bool
    {
        return count($this->segments) < count($path->segments)
            && array_slice($path->segments, 0, count($this->segments)) === $this->segments;
    }

    public function hasWildcard(): bool
    {
        return in_array('*', $this->segments, true);
    }

    /**
     * The segments joined with '.', as Laravel reports a key in its error bag: "items.0.code".
     */
    public function toKey(): string
    {
        return implode('.', $this->segments);
    }

    /**
     * The key for a Laravel rule, with a literal dot in a segment escaped as "\.".
     */
    public function toRuleKey(): string
    {
        return implode('.', array_map(fn (string $segment) => str_replace('.', '\\.', $segment), $this->segments));
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function existsIn(array $data): bool
    {
        $value = $data;

        foreach ($this->segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return false;
            }

            $value = $value[$segment];
        }

        return true;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function getFrom(array $data, mixed $default = null): mixed
    {
        $value = $data;

        foreach ($this->segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Replaces whatever is along the path but not an array with an array.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function setIn(array &$data, mixed $value): void
    {
        $ref = &$data;
        $last = count($this->segments) - 1;

        foreach ($this->segments as $i => $segment) {
            if ($i === $last) {
                $ref[$segment] = $value;

                return;
            }

            if (! isset($ref[$segment]) || ! is_array($ref[$segment])) {
                $ref[$segment] = [];
            }

            $ref = &$ref[$segment];
        }
    }
}

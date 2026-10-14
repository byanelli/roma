<?php

namespace BYanelli\Roma\Data;

use PhpToken;
use ReflectionClass;
use ReflectionParameter;
use ReflectionProperty;

/**
 * Resolves a class name in PHPDoc as PHP resolves one in code, through the `use` imports and
 * namespace of the class or trait that declares the property.
 */
class ClassNameResolver
{
    /**
     * Namespace blocks by file, each with its first line and its class imports by lowercase alias.
     *
     * @var array<string, list<array{int, array<string, string>}>>
     */
    private static array $files = [];

    /**
     * @param  ReflectionClass<object>  $scope
     */
    public function resolve(string $name, ReflectionClass $scope): string
    {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }

        $first = strtolower(explode('\\', $name, 2)[0]);
        $imports = $this->imports($scope);

        if (isset($imports[$first])) {
            return $imports[$first].substr($name, strlen($first));
        }

        $namespace = $scope->getNamespaceName();

        return $namespace === '' ? $name : "{$namespace}\\{$name}";
    }

    /**
     * The class or trait whose source declares the property or constructor parameter. Reflection
     * reports a trait's members as declared by the class that uses the trait.
     *
     * @return ?ReflectionClass<object>
     */
    public function declaringScope(ReflectionParameter|ReflectionProperty $obj): ?ReflectionClass
    {
        $class = $obj->getDeclaringClass();

        if ($class === null) {
            return null;
        }

        foreach ($this->traits($class) as $trait) {
            if ($obj instanceof ReflectionParameter) {
                $function = $obj->getDeclaringFunction();

                if ($function->getFileName() === $trait->getFileName()
                    && $function->getStartLine() >= $trait->getStartLine()
                    && $function->getEndLine() <= $trait->getEndLine()) {
                    return $trait;
                }
            } elseif ($trait->hasProperty($obj->getName())
                && $trait->getProperty($obj->getName())->getDocComment() === $obj->getDocComment()) {
                return $trait;
            }
        }

        return $class;
    }

    /**
     * A trait's own traits come before it, since a trait reports their members as its own.
     *
     * @param  ReflectionClass<object>  $class
     * @return list<ReflectionClass<object>>
     */
    private function traits(ReflectionClass $class): array
    {
        $traits = [];

        foreach ($class->getTraits() as $trait) {
            $traits = [...$traits, ...$this->traits($trait), $trait];
        }

        return $traits;
    }

    /**
     * @param  ReflectionClass<object>  $scope
     * @return array<string, string>
     */
    private function imports(ReflectionClass $scope): array
    {
        $file = $scope->getFileName();

        if ($file === false || ! is_file($file)) {
            return [];
        }

        $blocks = self::$files[$file] ??= $this->parse((string) file_get_contents($file));
        $imports = [];

        foreach ($blocks as [$line, $blockImports]) {
            if ($line <= $scope->getStartLine()) {
                $imports = $blockImports;
            }
        }

        return $imports;
    }

    /**
     * @return list<array{int, array<string, string>}>
     */
    private function parse(string $code): array
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($code), fn (PhpToken $token) => ! $token->isIgnorable()));
        $blocks = [];
        $line = 0;
        $imports = [];
        $depth = 0;
        $namespaceDepth = 0;

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if ($token->text === '{' || $token->is(T_DOLLAR_OPEN_CURLY_BRACES)) {
                $depth++;
            } elseif ($token->text === '}') {
                $depth--;
            } elseif ($token->is(T_NAMESPACE) && $depth === 0) {
                $end = $i;

                while (! in_array($tokens[$end]->text, [';', '{'], true)) {
                    $end++;
                }

                $blocks[] = [$line, $imports];
                $line = $token->line;
                $imports = [];
                $namespaceDepth = $tokens[$end]->text === '{' ? 1 : 0;
                $i = $end - 1;
            } elseif ($token->is(T_USE) && $depth === $namespaceDepth && ($tokens[$i - 1] ?? null)?->text !== ')') {
                $i = $this->parseUse($tokens, $i + 1, $imports);
            }
        }

        return [...$blocks, [$line, $imports]];
    }

    /**
     * Adds the class imports of a `use` statement and returns the index of its semicolon.
     *
     * @param  list<PhpToken>  $tokens
     * @param  array<string, string>  $imports
     */
    private function parseUse(array $tokens, int $i, array &$imports): int
    {
        $classes = ! $tokens[$i]->is([T_FUNCTION, T_CONST]);
        $i += $classes ? 0 : 1;

        while (true) {
            $name = ltrim($tokens[$i]->text, '\\');

            if ($tokens[$i + 1]->is(T_NS_SEPARATOR)) {
                // A group import: Prefix\{A, B as C, function f}
                for ($i += 3; $tokens[$i]->text !== '}';) {
                    $member = ! $tokens[$i]->is([T_FUNCTION, T_CONST]);
                    $i += $member ? 0 : 1;
                    $i = $this->addImport($tokens, $i, "{$name}\\{$tokens[$i]->text}", $classes && $member, $imports);
                    $i += $tokens[$i]->text === ',' ? 1 : 0;
                }

                $i++;
            } else {
                $i = $this->addImport($tokens, $i, $name, $classes, $imports);
            }

            if ($tokens[$i]->text !== ',') {
                return $i;
            }

            $i++;
        }
    }

    /**
     * Reads the imported name at `$i` and its optional alias, and returns the index after them.
     *
     * @param  list<PhpToken>  $tokens
     * @param  array<string, string>  $imports
     */
    private function addImport(array $tokens, int $i, string $name, bool $add, array &$imports): int
    {
        $alias = class_basename($name);
        $i++;

        if ($tokens[$i]->is(T_AS)) {
            $alias = $tokens[$i + 1]->text;
            $i += 2;
        }

        if ($add) {
            $imports[strtolower($alias)] = $name;
        }

        return $i;
    }
}

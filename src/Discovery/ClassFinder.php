<?php

namespace BYanelli\Roma\Discovery;

use FilesystemIterator;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Finds the fully-qualified names of the classes declared under a set of
 * directories, by reading each PHP file's namespace and top-level type name
 * from its tokens. Only classes that are actually autoloadable are returned.
 */
class ClassFinder
{
    /**
     * @param  list<string>  $paths
     * @return list<class-string>
     */
    public function classesIn(array $paths): array
    {
        $classes = [];

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            foreach ($this->phpFilesIn($path) as $file) {
                $class = $this->classDeclaredIn($file);

                if ($class !== null && class_exists($class)) {
                    // Keyed to dedupe; a file may be reached via overlapping paths.
                    $classes[$class] = true;
                }
            }
        }

        /** @var list<class-string> */
        return array_keys($classes);
    }

    /**
     * @return iterable<string>
     */
    private function phpFilesIn(string $path): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }

    /**
     * The fully-qualified name of the first class/interface/enum/trait declared
     * in the file, or null when the file declares no such type.
     */
    private function classDeclaredIn(string $file): ?string
    {
        $code = @file_get_contents($file);

        if ($code === false) {
            return null;
        }

        $tokens = PhpToken::tokenize($code);
        $count = count($tokens);
        $namespace = '';

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is(T_NAMESPACE)) {
                $namespace = $this->readName($tokens, $i + 1);
            } elseif ($token->is([T_CLASS, T_INTERFACE, T_ENUM, T_TRAIT])) {
                $name = $this->readTypeName($tokens, $i + 1);

                if ($name === null) {
                    // No name follows: an anonymous class or a `::class` constant.
                    continue;
                }

                return $namespace === '' ? $name : $namespace.'\\'.$name;
            }
        }

        return null;
    }

    /**
     * Reads a namespace name (a single T_STRING or T_NAME_QUALIFIED token in
     * PHP 8) starting from the given offset.
     *
     * @param  array<int, PhpToken>  $tokens
     */
    private function readName(array $tokens, int $from): string
    {
        for ($i = $from, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is(T_WHITESPACE)) {
                continue;
            }

            if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                return ltrim($token->text, '\\');
            }

            break;
        }

        return '';
    }

    /**
     * Reads the name immediately following a class/interface/enum/trait keyword,
     * or null when the next meaningful token is not a plain name (e.g. an
     * anonymous class, or the `class` keyword in a `::class` expression).
     *
     * @param  array<int, PhpToken>  $tokens
     */
    private function readTypeName(array $tokens, int $from): ?string
    {
        for ($i = $from, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is(T_WHITESPACE)) {
                continue;
            }

            return $token->is(T_STRING) ? $token->text : null;
        }

        return null;
    }
}

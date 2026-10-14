<?php

namespace BYanelli\Roma\Discovery;

use BYanelli\Roma\Attributes\AutoDiscriminated;
use Illuminate\Contracts\Foundation\Application;
use ReflectionClass;
use RuntimeException;

/**
 * The members of each #[AutoDiscriminated] interface or abstract class. When the cache file
 * that roma:cache writes exists, it is the only source, even if it is stale; otherwise the
 * roma.discover directories are scanned once per process.
 */
class DiscriminatedMembers
{
    /**
     * @var ?array<class-string, list<class-string>>
     */
    private ?array $members = null;

    public function __construct(
        private readonly Application $app,
        private readonly ClassFinder $finder = new ClassFinder,
    ) {}

    /**
     * @param  class-string  $contract
     * @return list<class-string>
     */
    public function of(string $contract): array
    {
        return $this->all()[$contract] ?? [];
    }

    /**
     * @return array<class-string, list<class-string>>
     */
    public function all(): array
    {
        return $this->members ??= $this->isCached() ? $this->readCache() : $this->scan();
    }

    /**
     * Every concrete class in the discover directories, grouped under each of its parents and
     * interfaces that has #[AutoDiscriminated]. A contract outside the directories is included
     * when a member is inside them.
     *
     * @return array<class-string, list<class-string>>
     */
    public function scan(): array
    {
        /** @var list<string> $paths */
        $paths = $this->app->make('config')->get('roma.discover', []);
        $isAuto = [];
        $members = [];

        foreach ($this->finder->concreteClasses($paths) as $class) {
            foreach ([...class_parents($class), ...class_implements($class)] as $ancestor) {
                $isAuto[$ancestor] ??= new ReflectionClass($ancestor)->getAttributes(AutoDiscriminated::class) !== [];

                if ($isAuto[$ancestor]) {
                    $members[$ancestor][] = $class;
                }
            }
        }

        ksort($members);

        return array_map(function (array $classes) {
            sort($classes);

            return $classes;
        }, $members);
    }

    /**
     * Scans the directories and writes the result to the cache file.
     *
     * @return array<class-string, list<class-string>>
     */
    public function cache(): array
    {
        $members = $this->scan();

        $directory = dirname($this->cachePath());

        if (! is_dir($directory)) {
            mkdir($directory, 0o755, recursive: true);
        }

        $contents = '<?php return '.var_export(['auto_discriminated' => $members], true).';'.PHP_EOL;

        if (file_put_contents($this->cachePath(), $contents) === false) {
            throw new RuntimeException("Roma could not write its cache file {$this->cachePath()}.");
        }

        $this->members = $members;

        return $members;
    }

    public function clear(): void
    {
        if (is_file($this->cachePath())) {
            unlink($this->cachePath());
        }

        $this->members = null;
    }

    public function isCached(): bool
    {
        return is_file($this->cachePath());
    }

    public function cachePath(): string
    {
        return $this->app->bootstrapPath('cache/roma.php');
    }

    /**
     * @return array<class-string, list<class-string>>
     */
    private function readCache(): array
    {
        $cached = require $this->cachePath();

        /** @var array<class-string, list<class-string>> */
        return is_array($cached) && is_array($cached['auto_discriminated'] ?? null) ? $cached['auto_discriminated'] : [];
    }
}

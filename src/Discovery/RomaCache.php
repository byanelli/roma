<?php

namespace BYanelli\Roma\Discovery;

use BYanelli\Roma\Attributes\AutoDiscriminated;
use BYanelli\Roma\Config\ConfigObject;
use Illuminate\Contracts\Foundation\Application;
use ReflectionClass;
use RuntimeException;

/**
 * The classes Roma finds in the roma.discover directories: the members of each
 * #[AutoDiscriminated] interface or abstract class, and the classes with a class-level
 * #[ConfigObject]. When the cache file that roma:cache writes exists, it is the only source,
 * even if it is stale; otherwise the directories are scanned once per process.
 *
 * @phpstan-type Discovered array{auto_discriminated: array<class-string, list<class-string>>, config_objects: list<class-string>}
 */
class RomaCache
{
    /**
     * @var ?Discovered
     */
    private ?array $discovered = null;

    public function __construct(
        private readonly Application $app,
        private readonly ClassFinder $finder = new ClassFinder,
    ) {}

    /**
     * @param  class-string  $contract
     * @return list<class-string>
     */
    public function membersOf(string $contract): array
    {
        return $this->members()[$contract] ?? [];
    }

    /**
     * @return array<class-string, list<class-string>>
     */
    public function members(): array
    {
        return $this->discovered()['auto_discriminated'];
    }

    /**
     * @return list<class-string>
     */
    public function configObjects(): array
    {
        return $this->discovered()['config_objects'];
    }

    /**
     * Scans the concrete classes in the discover directories. A member is listed under its
     * parents and interfaces that have #[AutoDiscriminated], including a contract outside the
     * directories.
     *
     * @return Discovered
     */
    public function scan(): array
    {
        /** @var list<string> $paths */
        $paths = $this->app->make('config')->get('roma.discover', []);
        $isAuto = [];
        $members = [];
        $configObjects = [];

        foreach ($this->finder->concreteClasses($paths) as $class) {
            foreach ([...class_parents($class), ...class_implements($class)] as $ancestor) {
                $isAuto[$ancestor] ??= new ReflectionClass($ancestor)->getAttributes(AutoDiscriminated::class) !== [];

                if ($isAuto[$ancestor]) {
                    $members[$ancestor][] = $class;
                }
            }

            if (ConfigObject::prefixOf($class) !== null) {
                $configObjects[] = $class;
            }
        }

        ksort($members);
        sort($configObjects);

        return [
            'auto_discriminated' => array_map(function (array $classes) {
                sort($classes);

                return $classes;
            }, $members),
            'config_objects' => $configObjects,
        ];
    }

    /**
     * Scans the directories and writes the result to the cache file.
     *
     * @return Discovered
     */
    public function cache(): array
    {
        $discovered = $this->scan();

        $directory = dirname($this->cachePath());

        if (! is_dir($directory)) {
            mkdir($directory, 0o755, recursive: true);
        }

        $contents = '<?php return '.var_export($discovered, true).';'.PHP_EOL;

        if (file_put_contents($this->cachePath(), $contents) === false) {
            throw new RuntimeException("Roma could not write its cache file {$this->cachePath()}.");
        }

        return $this->discovered = $discovered;
    }

    public function clear(): void
    {
        if (is_file($this->cachePath())) {
            unlink($this->cachePath());
        }

        $this->discovered = null;
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
     * @return Discovered
     */
    private function discovered(): array
    {
        return $this->discovered ??= $this->isCached() ? $this->readCache() : $this->scan();
    }

    /**
     * A key missing from the file is empty.
     *
     * @return Discovered
     */
    private function readCache(): array
    {
        $cached = require $this->cachePath();

        /** @var Discovered */
        return [
            'auto_discriminated' => is_array($cached['auto_discriminated'] ?? null) ? $cached['auto_discriminated'] : [],
            'config_objects' => is_array($cached['config_objects'] ?? null) ? $cached['config_objects'] : [],
        ];
    }
}

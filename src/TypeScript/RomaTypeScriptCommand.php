<?php

namespace BYanelli\Roma\TypeScript;

use BYanelli\Roma\Discovery\RomaClassDiscovery;
use Illuminate\Console\Command;

class RomaTypeScriptCommand extends Command
{
    protected $signature = 'roma:typescript {--output= : Path to write the .d.ts file to (overrides config)}';

    protected $description = 'Generate TypeScript definitions for Roma request and response objects';

    public function handle(RomaClassDiscovery $discovery): int
    {
        /** @var list<string> $paths */
        $paths = config('roma.typescript.discover', []);
        $discovered = $discovery->discover($paths);

        /** @var list<class-string> $requests */
        $requests = $this->merge(config('roma.typescript.requests', []), $discovered->requests);
        /** @var list<class-string> $responses */
        $responses = $this->merge(config('roma.typescript.responses', []), $discovered->responses);

        if ($requests === [] && $responses === []) {
            $this->warn('No request or response classes found. Mark requests with #[Request], extend Response (or use IsResponsable), and check roma.typescript.discover. Nothing to generate.');

            return self::SUCCESS;
        }

        $output = $this->option('output')
            ?: config('roma.typescript.output', base_path('resources/js/roma.d.ts'));

        $typescript = new TypeScriptGenerator($requests, $responses)->generate();

        $directory = dirname($output);

        if (! is_dir($directory)) {
            mkdir($directory, 0o755, recursive: true);
        }

        file_put_contents($output, $typescript);

        $this->info(sprintf(
            'Generated TypeScript for %d request and %d response %s to %s',
            count($requests),
            count($responses),
            count($requests) + count($responses) === 1 ? 'class' : 'classes',
            $output,
        ));

        return self::SUCCESS;
    }

    /**
     * Combine explicitly-configured classes with discovered ones, preserving
     * order and dropping duplicates.
     *
     * @param  list<class-string>  $configured
     * @param  list<class-string>  $discovered
     * @return list<class-string>
     */
    private function merge(array $configured, array $discovered): array
    {
        return array_values(array_unique([...$configured, ...$discovered]));
    }
}

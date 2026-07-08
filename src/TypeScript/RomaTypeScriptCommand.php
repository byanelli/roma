<?php

namespace BYanelli\Roma\TypeScript;

use Illuminate\Console\Command;

class RomaTypeScriptCommand extends Command
{
    protected $signature = 'roma:typescript {--output= : Path to write the .d.ts file to (overrides config)}';

    protected $description = 'Generate TypeScript definitions for Roma request and response objects';

    public function handle(): int
    {
        /** @var list<class-string> $requests */
        $requests = config('roma.typescript.requests', []);
        /** @var list<class-string> $responses */
        $responses = config('roma.typescript.responses', []);

        if ($requests === [] && $responses === []) {
            $this->warn('No request or response classes configured under roma.typescript. Nothing to generate.');

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
}

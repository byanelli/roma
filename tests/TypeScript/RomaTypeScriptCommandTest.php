<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Http\Response\Response;

class CmdPingResponse extends Response
{
    public function __construct(
        public string $message,
        public int $code,
    ) {}
}

enum CmdColor: string
{
    case Red = 'red';
}

class CmdColorResponse extends Response
{
    public function __construct(
        public CmdColor $color,
    ) {}
}

beforeEach(function () {
    // Keep these tests deterministic: opt out of directory scanning unless a
    // test sets it explicitly.
    config()->set('roma.discover', []);
});

it('writes generated TypeScript to the configured output path', function () {
    $output = sys_get_temp_dir().'/roma_ts_'.uniqid().'.ts';

    config()->set('roma.typescript.responses', [CmdPingResponse::class]);
    config()->set('roma.typescript.output', $output);

    $this->artisan('roma:typescript')->assertSuccessful();

    expect(file_get_contents($output))
        ->toContain('export interface CmdPingResponseBody {')
        ->toContain('  message: string;')
        ->toContain('  code: number;');

    @unlink($output);
});

it('auto-detects request and response classes in the discover paths', function () {
    $output = sys_get_temp_dir().'/roma_ts_'.uniqid().'.ts';

    config()->set('roma.discover', [dirname(__DIR__).'/Fixtures/Discovery']);
    config()->set('roma.typescript.output', $output);

    $this->artisan('roma:typescript')->assertSuccessful();

    expect(file_get_contents($output))
        ->toContain('export interface SampleRequestBody {')
        ->toContain('export interface SampleResponseBody {')
        ->toContain('export interface ResponsableSampleBody {');

    @unlink($output);
});

it('defaults to a .ts file', function () {
    expect(config('roma.typescript.output'))->toEndWith('resources/js/roma.ts');
});

it('writes enum constants to a .ts file', function () {
    $output = sys_get_temp_dir().'/roma_ts_'.uniqid().'.ts';

    config()->set('roma.typescript.responses', [CmdColorResponse::class]);

    $this->artisan('roma:typescript', ['--output' => $output])->assertSuccessful();

    expect(file_get_contents($output))->toContain('export const CmdColor = {');

    @unlink($output);
});

it('fails for a .d.ts output path', function (string $path, array $options) {
    $output = sys_get_temp_dir().'/'.uniqid('roma_ts_').$path;

    config()->set('roma.typescript.responses', [CmdPingResponse::class]);
    config()->set('roma.typescript.output', sys_get_temp_dir().'/'.uniqid('roma_ts_').'.ts');

    if ($options === []) {
        config()->set('roma.typescript.output', $output);
    } else {
        $options = ['--output' => $output];
    }

    $this->artisan('roma:typescript', $options)
        ->expectsOutputToContain("Roma can't write TypeScript to {$output}")
        ->assertFailed();

    expect(file_exists($output))->toBeFalse();
})->with([
    'from config' => ['.d.ts', []],
    'from --output' => ['.d.ts', ['--output']],
    'in uppercase' => ['.D.TS', ['--output']],
]);

it('fails for a .d.ts output path when no classes are found', function () {
    config()->set('roma.typescript.output', sys_get_temp_dir().'/roma.d.ts');

    $this->artisan('roma:typescript')->assertFailed();
});

it('uses the --output path instead of a .d.ts path in config', function () {
    $output = sys_get_temp_dir().'/roma_ts_'.uniqid().'.ts';

    config()->set('roma.typescript.responses', [CmdPingResponse::class]);
    config()->set('roma.typescript.output', sys_get_temp_dir().'/roma.d.ts');

    $this->artisan('roma:typescript', ['--output' => $output])->assertSuccessful();

    expect(file_exists($output))->toBeTrue();

    @unlink($output);
});

it('reports nothing to do when no classes are configured or discovered', function () {
    config()->set('roma.typescript.requests', []);
    config()->set('roma.typescript.responses', []);

    $this->artisan('roma:typescript')
        ->expectsOutputToContain('Nothing to generate')
        ->assertSuccessful();
});

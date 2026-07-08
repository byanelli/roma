<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Response\Response;

class CmdPingResponse extends Response
{
    public function __construct(
        public string $message,
        public int $code,
    ) {}
}

it('writes generated TypeScript to the configured output path', function () {
    $output = sys_get_temp_dir().'/roma_ts_'.uniqid().'.d.ts';

    config()->set('roma.typescript.responses', [CmdPingResponse::class]);
    config()->set('roma.typescript.output', $output);

    $this->artisan('roma:typescript')->assertSuccessful();

    expect(file_get_contents($output))
        ->toContain('export interface CmdPingResponseBody {')
        ->toContain('  message: string;')
        ->toContain('  code: number;');

    @unlink($output);
});

it('reports nothing to do when no classes are configured', function () {
    config()->set('roma.typescript.requests', []);
    config()->set('roma.typescript.responses', []);

    $this->artisan('roma:typescript')
        ->expectsOutputToContain('Nothing to generate')
        ->assertSuccessful();
});

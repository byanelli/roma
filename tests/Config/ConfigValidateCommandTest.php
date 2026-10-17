<?php

use BYanelli\Roma\Discovery\RomaCache;
use BYanelli\Roma\Tests\Fixtures\Config\MailerConfig;
use BYanelli\Roma\Tests\Fixtures\Config\ScraperConfig;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;

function configCache(): RomaCache
{
    return app(RomaCache::class);
}

/**
 * @return array{int, string}
 */
function validateConfig(): array
{
    $code = Artisan::call('roma:config:validate');

    return [$code, Artisan::output()];
}

beforeEach(function () {
    config()->set('roma.discover', [dirname(__DIR__).'/Fixtures/Config']);
    configCache()->clear();
});

afterEach(function () {
    configCache()->clear();
});

it('finds the classes with a class-level #[ConfigObject]', function () {
    expect(configCache()->configObjects())->toBe([MailerConfig::class, ScraperConfig::class]);
});

it('prints OK for each valid class and exits with 0', function () {
    config(['services.mailer.api_key' => 'key_1', 'services.scraper.provider' => 'zyte']);

    [$code, $output] = validateConfig();

    expect($code)->toBe(0)
        ->and($output)->toMatch('/'.preg_quote(MailerConfig::class, '/').' \.+ OK/')
        ->and($output)->toMatch('/'.preg_quote(ScraperConfig::class, '/').' \.+ OK/');
});

it('prints the errors of each invalid class and exits with 1', function () {
    config(['services.mailer.api_key' => 'key_1', 'services.scraper' => ['provider' => 'oxylabs', 'timeout' => '0']]);

    [$code, $output] = validateConfig();

    expect($code)->toBe(1)
        ->and($output)->toMatch('/'.preg_quote(MailerConfig::class, '/').' \.+ OK/')
        ->and($output)->toMatch('/'.preg_quote(ScraperConfig::class, '/').' \.+ FAIL/')
        ->and($output)->toContain('    services.scraper.provider  The services.scraper.provider field must be one of: scrapfly, zyte.')
        ->and($output)->toContain('    services.scraper.timeout  The services.scraper.timeout field must be at least 1.')
        ->and($output)->toContain('1 of 2 config objects failed validation.');
});

it('says so when there are no config objects', function () {
    config()->set('roma.discover', []);

    [$code, $output] = validateConfig();

    expect($code)->toBe(0)
        ->and($output)->toContain('No config objects found in the roma.discover directories.');
});

it('reads the classes from the cache file while it exists', function () {
    /** @var TestCase $this */
    $this->artisan('roma:cache')
        ->expectsOutputToContain('Cached 0 #[AutoDiscriminated] unions and 2 config objects')
        ->assertSuccessful();

    expect((require configCache()->cachePath())['config_objects'])->toBe([MailerConfig::class, ScraperConfig::class]);

    config()->set('roma.discover', []);
    app()->forgetInstance(RomaCache::class);

    [$code, $output] = validateConfig();

    expect($code)->toBe(1)
        ->and($output)->toContain('services.mailer.api_key  The services.mailer.api_key field is required.');
});

it('runs with optimize', function () {
    expect(ServiceProvider::$optimizeCommands['roma config'] ?? null)->toBe('roma:config:validate');
});

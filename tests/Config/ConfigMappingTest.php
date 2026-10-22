<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Key;
use BYanelli\Roma\Attributes\Rule;
use BYanelli\Roma\Config\ConfigKey;
use BYanelli\Roma\Config\ConfigObject;
use BYanelli\Roma\Config\ConfigValidationException;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Tests\Fixtures\Config\Credentials;
use BYanelli\Roma\Tests\Fixtures\Config\ScraperConfig;
use BYanelli\Roma\Tests\Fixtures\Config\ScraperProvider;
use Illuminate\Contracts\Validation\ValidationRule;

#[ConfigObject('services.scrapfly')]
final readonly class CfgScrapfly
{
    public function __construct(
        #[Key('key')]
        public string $apiKey,
        #[ConfigKey('articles.scrapfly_timeout')]
        #[Rule('min:1')]
        public int $timeout = 180,
        #[ConfigKey('articles.scrapfly_country')]
        #[Rule('size:2')]
        public string $country = 'us',
        public bool $renderJs = false,
        public ?string $session = null,
    ) {}
}

/**
 * @throws ConfigValidationException
 */
function configErrors(callable $map): array
{
    try {
        $map();
    } catch (ConfigValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('Expected a ConfigValidationException');
}

it('reads keys under the prefix, snake_cased, and absolute keys', function () {
    config([
        'services.scrapfly' => ['key' => 'sk_123', 'render_js' => 'true', 'session' => 'abc'],
        'articles' => ['scrapfly_timeout' => '90', 'scrapfly_country' => 'de'],
    ]);

    expect(Mapper::fromConfig(CfgScrapfly::class))
        ->toEqual(new CfgScrapfly(apiKey: 'sk_123', timeout: 90, country: 'de', renderJs: true, session: 'abc'));
});

it('applies defaults to missing, null and blank values, and null to a nullable property', function (mixed $blank) {
    config([
        'services.scrapfly' => ['key' => 'sk_123', 'render_js' => $blank, 'session' => $blank],
        'articles' => ['scrapfly_timeout' => $blank],
    ]);

    expect(Mapper::fromConfig(CfgScrapfly::class))->toEqual(new CfgScrapfly(apiKey: 'sk_123'));
})->with([
    'null' => [null],
    'empty string' => [''],
    'whitespace' => ["  \t"],
]);

it('reports a missing, null or blank required key by its full config key', function (array $scrapfly) {
    config(['services.scrapfly' => $scrapfly]);

    expect(configErrors(fn () => Mapper::fromConfig(CfgScrapfly::class)))
        ->toBe(['services.scrapfly.key' => ['The services.scrapfly.key field is required.']]);
})->with([
    'missing' => [[]],
    'null' => [['key' => null]],
    'blank' => [['key' => ' ']],
]);

it('reports a missing prefix as required keys', function () {
    expect(configErrors(fn () => Mapper::fromConfig(CfgScrapfly::class)))
        ->toBe(['services.scrapfly.key' => ['The services.scrapfly.key field is required.']]);
});

it('reports rule and type errors by their full config keys', function () {
    config([
        'services.scrapfly' => ['key' => 'sk_123', 'render_js' => ['maybe']],
        'articles' => ['scrapfly_timeout' => '0', 'scrapfly_country' => 'usa'],
    ]);

    expect(configErrors(fn () => Mapper::fromConfig(CfgScrapfly::class)))->toBe([
        'articles.scrapfly_timeout' => ['The articles.scrapfly_timeout field must be at least 1.'],
        'articles.scrapfly_country' => ['The articles.scrapfly_country field must be 2 characters.'],
        'services.scrapfly.render_js' => ['The services.scrapfly.render_js field must be true or false.'],
    ]);
});

// The base class lists only the first three errors.
it('throws a ConfigValidationException that lists the class and all the errors', function () {
    config([
        'services.scrapfly' => ['render_js' => ['maybe']],
        'articles' => ['scrapfly_timeout' => 'soon', 'scrapfly_country' => 'usa'],
    ]);

    expect(fn () => Mapper::fromConfig(CfgScrapfly::class))->toThrow(function (ConfigValidationException $e) {
        expect($e)->toBeInstanceOf(MappingValidationException::class)
            ->and($e->className())->toBe(CfgScrapfly::class)
            ->and($e->getMessage())->toBe(
                'Roma config object CfgScrapfly is invalid:'."\n"
                .'  services.scrapfly.key: The services.scrapfly.key field is required.'."\n"
                .'  articles.scrapfly_timeout: The articles.scrapfly_timeout field must be an integer.'."\n"
                .'  articles.scrapfly_country: The articles.scrapfly_country field must be 2 characters.'."\n"
                .'  services.scrapfly.render_js: The services.scrapfly.render_js field must be true or false.'
            );
    });
});

#[ConfigObject('services.proxies')]
final readonly class CfgProxies
{
    /**
     * @param  list<string>  $countries
     * @param  list<CfgProxyServer>  $servers
     */
    public function __construct(
        public Credentials $oxylabs,
        public ?Credentials $dataImpulse = null,
        #[Key('limits.per_minute')]
        public int $perMinute = 60,
        public array $countries = [],
        public array $servers = [],
    ) {}
}

final readonly class CfgProxyServer
{
    public function __construct(
        public string $hostName,
        public int $port = 8080,
    ) {}
}

it('reads nested objects, lists and dotted keys relative to the prefix', function () {
    config(['services.proxies' => [
        'oxylabs' => ['user' => 'ox', 'password' => 'secret'],
        'data_impulse' => ['user' => 'di', 'password' => 'hunter2'],
        'limits' => ['per_minute' => '30'],
        'countries' => ['us', 'de'],
        'servers' => [['host_name' => 'a.example.com', 'port' => '81'], ['host_name' => 'b.example.com']],
    ]]);

    expect(Mapper::fromConfig(CfgProxies::class))->toEqual(new CfgProxies(
        oxylabs: new Credentials('ox', 'secret'),
        dataImpulse: new Credentials('di', 'hunter2'),
        perMinute: 30,
        countries: ['us', 'de'],
        servers: [new CfgProxyServer('a.example.com', 81), new CfgProxyServer('b.example.com')],
    ));
});

final readonly class CfgEscapedDot
{
    public function __construct(
        #[Key('hosts.api\.example\.com')]
        public string $token,
    ) {}
}

it('reads an escaped dot in a config key as part of the key', function () {
    config(['services.escaped' => ['hosts' => ['api.example.com' => 'abc']]]);

    expect(Mapper::fromConfig(CfgEscapedDot::class, 'services.escaped')->token)->toBe('abc');

    config(['services.escaped' => ['hosts' => ['api' => ['example' => ['com' => 'abc']]]]]);

    expect(configErrors(fn () => Mapper::fromConfig(CfgEscapedDot::class, 'services.escaped')))->toBe([
        'services.escaped.hosts.api.example.com' => ['The services.escaped.hosts.api.example.com field is required.'],
    ]);
});

it('treats a nested object whose values are all blank as missing', function () {
    config(['services.proxies' => [
        'oxylabs' => ['user' => 'ox', 'password' => 'secret'],
        'data_impulse' => ['user' => '', 'password' => null],
    ]]);

    expect(Mapper::fromConfig(CfgProxies::class)->dataImpulse)->toBeNull();
});

it('reports errors in nested objects and lists by their full config keys', function () {
    config(['services.proxies' => [
        'oxylabs' => ['user' => 'ox', 'password' => ''],
        'servers' => [['host_name' => 'a.example.com'], ['port' => 'x']],
    ]]);

    expect(configErrors(fn () => Mapper::fromConfig(CfgProxies::class)))->toBe([
        'services.proxies.oxylabs.password' => ['The services.proxies.oxylabs.password field is required.'],
        'services.proxies.servers.1.host_name' => ['The services.proxies.servers.1.host_name field is required.'],
        'services.proxies.servers.1.port' => ['The services.proxies.servers.1.port field must be an integer.'],
    ]);
});

it('lists the valid values of an enum', function () {
    config(['services.scraper.provider' => 'oxylabs']);

    expect(configErrors(fn () => Mapper::fromConfig(ScraperConfig::class)))
        ->toBe(['services.scraper.provider' => ['The services.scraper.provider field must be one of: scrapfly, zyte.']]);

    config(['services.scraper.provider' => 'zyte']);

    expect(Mapper::fromConfig(ScraperConfig::class)->provider)->toBe(ScraperProvider::Zyte);
});

it('maps with a prefix given to fromConfig()', function () {
    config(['services.oxylabs.residential' => ['user' => 'ox', 'password' => 'secret']]);

    expect(Mapper::fromConfig(Credentials::class, 'services.oxylabs.residential'))->toEqual(new Credentials('ox', 'secret'));
});

it('throws for a class with no prefix', function () {
    expect(fn () => Mapper::fromConfig(Credentials::class))
        ->toThrow(RuntimeException::class, 'Roma can\'t map '.Credentials::class.' from config: it has no #[ConfigObject] prefix.');
});

final class CfgEchoesValue implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $fail("The :attribute field may not be {$value}.");
    }
}

#[ConfigObject('services.vault')]
final readonly class CfgVault
{
    public function __construct(
        #[SensitiveParameter]
        #[Rule(new CfgEchoesValue)]
        public string $token,
        #[Rule(new CfgEchoesValue)]
        public string $region,
        public ?Credentials $admin = null,
    ) {}
}

it('keeps the values of sensitive parameters out of the exception', function () {
    config(['services.vault' => [
        'token' => 'tok_live_9f8e7d',
        'region' => 'eu',
        'admin' => ['user' => 'root', 'password' => 'pw_4c3b2a'],
    ]]);
    // The password is valid, so put it in another message.
    config(['services.vault.region' => 'pw_4c3b2a and tok_live_9f8e7d']);

    expect(fn () => Mapper::fromConfig(CfgVault::class))->toThrow(function (ConfigValidationException $e) {
        $dump = $e->getMessage().json_encode($e->errors());

        expect($dump)->not->toContain('tok_live_9f8e7d')
            ->and($dump)->not->toContain('pw_4c3b2a')
            ->and($e->errors())->toBe([
                'services.vault.token' => ['The services.vault.token field may not be [redacted].'],
                'services.vault.region' => ['The services.vault.region field may not be [redacted] and [redacted].'],
            ])
            ->and($e->getPrevious())->toBeNull();
    });
});

it('rejects #[ConfigKey] without a key within a file', function () {
    expect(fn () => new ConfigKey('app'))->toThrow(RuntimeException::class, 'Roma #[ConfigKey(\'app\')] needs a key within a config file')
        ->and(fn () => new ConfigKey('app.'))->toThrow(RuntimeException::class, 'needs a key within a config file');
});

final readonly class CfgNestedWithConfigKey
{
    public function __construct(
        #[ConfigKey('app.name')]
        public string $name,
    ) {}
}

#[ConfigObject('services.nested')]
final readonly class CfgHasNestedConfigKey
{
    public function __construct(public CfgNestedWithConfigKey $nested) {}
}

it('rejects #[ConfigKey] in a nested object', function () {
    expect(fn () => Mapper::fromConfig(CfgHasNestedConfigKey::class))
        ->toThrow(RuntimeException::class, 'is inside a nested object and cannot declare its own source');
});

final readonly class CfgArrayWithConfigKey
{
    public function __construct(
        #[ConfigKey('app.name')]
        public string $name,
    ) {}
}

it('rejects #[ConfigKey] outside a config object', function () {
    expect(fn () => Mapper::fromArray(CfgArrayWithConfigKey::class, ['name' => 'x']))
        ->toThrow(RuntimeException::class, 'maps from the "app" source, which ArrayPolicy does not allow');
});

it('rejects an empty prefix', function () {
    expect(fn () => new ConfigObject(' '))->toThrow(RuntimeException::class, 'Roma #[ConfigObject] needs a config key prefix');
});

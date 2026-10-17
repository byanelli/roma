<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Config\ConfigObject;
use BYanelli\Roma\Config\ConfigValidationException;
use BYanelli\Roma\Tests\Fixtures\Config\Credentials;
use BYanelli\Roma\Tests\Fixtures\Config\MailerConfig;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Container\BindingResolutionException;

#[ConfigObject('services.shared'), Singleton]
final readonly class CfgSingleton
{
    public function __construct(public string $name) {}
}

#[ConfigObject('services.default_creds')]
final readonly class CfgClassLevelCredentials
{
    public function __construct(public string $user, public string $password) {}
}

final readonly class CfgProxyClient
{
    public function __construct(
        #[ConfigObject('services.oxylabs.residential')] public Credentials $oxylabs,
        #[ConfigObject('services.data_impulse.residential')] public Credentials $dataImpulse,
        #[ConfigObject('services.override_creds')] public CfgClassLevelCredentials $override,
        public CfgClassLevelCredentials $classLevel,
    ) {}
}

it('resolves a class with a class-level #[ConfigObject] from the container', function () {
    config(['services.mailer' => ['api_key' => 'key_1']]);

    expect(app(MailerConfig::class))->toEqual(new MailerConfig('key_1'));
});

it('resolves a new object each time, so config changes apply', function () {
    config(['services.mailer' => ['api_key' => 'key_1']]);

    $first = app(MailerConfig::class);

    config(['services.mailer.api_key' => 'key_2']);

    expect(app(MailerConfig::class)->apiKey)->toBe('key_2')
        ->and($first->apiKey)->toBe('key_1')
        ->and(app()->isShared(MailerConfig::class))->toBeFalse();
});

it('shares an object whose class has #[Singleton]', function () {
    config(['services.shared.name' => 'first']);

    $first = app(CfgSingleton::class);

    config(['services.shared.name' => 'second']);

    expect(app(CfgSingleton::class))->toBe($first)
        ->and($first->name)->toBe('first')
        ->and(app()->isShared(CfgSingleton::class))->toBeTrue();
});

it('resolves constructor parameters with a parameter-level prefix', function () {
    config([
        'services.oxylabs.residential' => ['user' => 'ox', 'password' => 'secret'],
        'services.data_impulse.residential' => ['user' => 'di', 'password' => 'hunter2'],
        'services.override_creds' => ['user' => 'over', 'password' => 'pw'],
        'services.default_creds' => ['user' => 'default', 'password' => 'pw'],
    ]);

    $client = app(CfgProxyClient::class);

    expect($client->oxylabs)->toEqual(new Credentials('ox', 'secret'))
        ->and($client->dataImpulse)->toEqual(new Credentials('di', 'hunter2'))
        ->and($client->override->user)->toBe('over')
        ->and($client->classLevel->user)->toBe('default');
});

it('throws on resolve when the config is invalid', function () {
    expect(fn () => app(MailerConfig::class))->toThrow(
        ConfigValidationException::class,
        'Roma config object '.MailerConfig::class." is invalid:\n  services.mailer.api_key: The services.mailer.api_key field is required.",
    );
});

it('leaves an explicit binding alone', function () {
    app()->bind(MailerConfig::class, fn () => new MailerConfig('bound'));

    expect(app(MailerConfig::class)->apiKey)->toBe('bound');
});

final class CfgUntypedParameter
{
    public function __construct(#[ConfigObject('services.x')] public $creds) {}
}

it('rejects a parameter-level #[ConfigObject] on a parameter without a class type', function () {
    expect(fn () => app(CfgUntypedParameter::class))
        ->toThrow(BindingResolutionException::class, 'needs the parameter to be typed with a class');
});

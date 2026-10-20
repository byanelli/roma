<?php

namespace BYanelli\Roma;

use BYanelli\Roma\Config\ConfigMapper;
use BYanelli\Roma\Config\ConfigObject;
use BYanelli\Roma\Config\ConfigValidateCommand;
use BYanelli\Roma\Console\RomaCacheCommand;
use BYanelli\Roma\Console\RomaClearCommand;
use BYanelli\Roma\Discovery\RomaCache;
use BYanelli\Roma\Http\Client\Contracts\TypedFactory as TypedFactoryContract;
use BYanelli\Roma\Http\Client\TypedFactory;
use BYanelli\Roma\Http\Request\Contracts;
use BYanelli\Roma\Http\Request\Injection\Request;
use BYanelli\Roma\Http\Request\RequestMapper;
use BYanelli\Roma\Http\Request\RequestResolver;
use BYanelli\Roma\Serialization\Serializer;
use BYanelli\Roma\TypeScript\RomaTypeScriptCommand;
use Illuminate\Contracts\Container\Container;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class RomaServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('roma')
            ->hasConfigFile()
            ->hasCommands([RomaTypeScriptCommand::class, RomaCacheCommand::class, RomaClearCommand::class, ConfigValidateCommand::class]);

        $this->app->bind(Contracts\RequestResolver::class, fn () => $this->app->make(RequestResolver::class));
        $this->app->bind(Contracts\RequestMapper::class, fn () => $this->app->make(RequestMapper::class));
        $this->app->singleton(Serializer::class);
        $this->app->singleton(TypedFactoryContract::class, TypedFactory::class);
        $this->app->singleton(RomaCache::class);
    }

    /**
     * Config objects resolve from the register phase on, so other providers can use them in boot().
     */
    public function packageRegistered(): void
    {
        $this->registerConfigObjectResolution();
    }

    public function packageBooted(): void
    {
        $this->registerRequestAutoResolution();

        $this->optimizes(optimize: 'roma:cache', clear: 'roma:clear', key: 'roma');
        // optimize reports a failed task and continues, so a deploy must run the command itself to stop on invalid config.
        $this->optimizes(optimize: 'roma:config:validate', key: 'roma config');
    }

    /**
     * Binds a class with a class-level #[ConfigObject] to a lazy proxy on its first resolution,
     * so a class can inject config it doesn't use without it being validated. The binding isn't
     * shared, so the object reflects the current config, unless the class has Laravel's
     * #[Singleton] or #[Scoped].
     */
    private function registerConfigObjectResolution(): void
    {
        $this->app->beforeResolving(function (string $abstract, array $parameters, Container $app): void {
            if ($app->bound($abstract) || ConfigObject::prefixOf($abstract) === null) {
                return;
            }

            /** @var class-string $abstract */
            $app->bind($abstract, fn (Container $app) => $app->make(ConfigMapper::class)->lazy($abstract));
        });
    }

    /**
     * Lets a class carrying a class-level #[Request] attribute be resolved from
     * the container by type-hint alone, without the parameter-level #[Request]
     * hint. The first time such a class is resolved we bind it to the mapper;
     * the binding is then used for all later resolutions, so the reflection cost is
     * paid once per class per process and skipped entirely once bound.
     *
     * The gate is checked only once we know we are looking at an unbound,
     * #[Request]-marked class, so a disabled config never adds work to the hot
     * path of ordinary container resolution.
     */
    private function registerRequestAutoResolution(): void
    {
        $this->app->beforeResolving(function (string $abstract, array $parameters, Container $app): void {
            if ($app->bound($abstract) || ! Request::isMarkedOn($abstract)) {
                return;
            }

            if (! config('roma.auto_inject', true)) {
                return;
            }

            // Narrowed by the isMarkedOn() guard above: $abstract is a class name.
            /** @var class-string $abstract */
            $app->bind(
                $abstract,
                fn (Container $app) => $app->make(Contracts\RequestMapper::class)->mapRequest($abstract),
            );
        });
    }
}

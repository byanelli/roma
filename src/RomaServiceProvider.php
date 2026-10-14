<?php

namespace BYanelli\Roma;

use BYanelli\Roma\Console\RomaCacheCommand;
use BYanelli\Roma\Console\RomaClearCommand;
use BYanelli\Roma\Discovery\DiscriminatedMembers;
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
            ->hasCommands([RomaTypeScriptCommand::class, RomaCacheCommand::class, RomaClearCommand::class]);

        $this->app->bind(Contracts\RequestResolver::class, fn () => $this->app->make(RequestResolver::class));
        $this->app->bind(Contracts\RequestMapper::class, fn () => $this->app->make(RequestMapper::class));
        $this->app->singleton(Serializer::class);
        $this->app->singleton(DiscriminatedMembers::class);
    }

    public function packageBooted(): void
    {
        $this->registerRequestAutoResolution();

        $this->optimizes(optimize: 'roma:cache', clear: 'roma:clear', key: 'roma');
    }

    /**
     * Lets a class carrying a class-level #[Request] attribute be resolved from
     * the container by type-hint alone, without the parameter-level #[Request]
     * hint. The first time such a class is resolved we bind it to the mapper;
     * the binding then serves every later resolution, so the reflection cost is
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

            // Narrowed by the isMarkedOn() guard above: $abstract names a class.
            /** @var class-string $abstract */
            $app->bind(
                $abstract,
                fn (Container $app) => $app->make(Contracts\RequestMapper::class)->mapRequest($abstract),
            );
        });
    }
}

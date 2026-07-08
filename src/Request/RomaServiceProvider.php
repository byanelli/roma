<?php

namespace BYanelli\Roma\Request;

use BYanelli\Roma\TypeScript\RomaTypeScriptCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class RomaServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('roma')
            ->hasConfigFile()
            ->hasCommand(RomaTypeScriptCommand::class);

        $this->app->bind(Contracts\RequestResolver::class, fn () => $this->app->make(RequestResolver::class));
        $this->app->bind(Contracts\RequestMapper::class, fn () => $this->app->make(RequestMapper::class));
    }
}

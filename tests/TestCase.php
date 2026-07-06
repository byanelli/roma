<?php

namespace BYanelli\Roma\Tests;

use BYanelli\Roma\Request\Contracts\RequestMapper;
use BYanelli\Roma\Request\RomaServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            RomaServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
    }

    public function setRequest(
        array $query = [],
        array $headers = [],
        array $files = [],
        ?array $json = null,
        array $routeParams = [],
        array $cookies = [],
    ): void {
        $server = collect($headers)->mapWithKeys(function ($value, $key) {
            $key = (($key != 'Content-Type') ? 'HTTP_' : '')
                .str_replace('-', '_', strtoupper($key));

            return [$key => $value];
        })->toArray();

        $request = ($json != null)
            ? new Request(query: $query, cookies: $cookies, files: $files, server: $server, content: json_encode($json))
            : new Request(query: $query, cookies: $cookies, files: $files, server: $server);

        if (! empty($routeParams)) {
            $route = new Route(['GET'], '/', []);
            $route->bind($request);

            foreach ($routeParams as $name => $value) {
                $route->setParameter($name, $value);
            }

            $request->setRouteResolver(fn () => $route);
        }

        $this->app->bind('request', fn () => $request);
    }

    public function getRequestMapper(): RequestMapper
    {
        return $this->app->make(RequestMapper::class);
    }

    /**
     * @template T
     *
     * @param  class-string<T>  $class
     * @return T
     *
     * @throws ValidationException
     * @throws \ReflectionException
     */
    public function mapRequest(string $class): mixed
    {
        return $this->getRequestMapper()->mapRequest($class);
    }
}

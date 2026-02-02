<?php

namespace CleverSo\AzureManagedIdentity\Providers;

use Illuminate\Support\ServiceProvider;
use CleverSo\AzureManagedIdentity\Console\Commands\TestAzureConnection;
use CleverSo\AzureManagedIdentity\Console\Commands\TestAzureRedisConnection;

class CommandServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                TestAzureConnection::class,
                TestAzureRedisConnection::class,
            ]);
        }
    }
}

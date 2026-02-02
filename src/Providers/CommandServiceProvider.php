<?php

namespace MI\AzureManagedIdentity\Providers;

use Illuminate\Support\ServiceProvider;
use MI\AzureManagedIdentity\Console\Commands\TestAzureConnection;
use MI\AzureManagedIdentity\Console\Commands\TestAzureRedisConnection;

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

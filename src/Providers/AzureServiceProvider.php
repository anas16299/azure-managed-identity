<?php

namespace MI\AzureManagedIdentity\Providers;

use League\Flysystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MI\AzureManagedIdentity\Filesystem\AzureAdapter;
use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;
use MI\AzureManagedIdentity\Services\ManagedIdentityBlobRestProxy;
use MI\AzureManagedIdentity\Services\AzureManagedIdentityTokenService;
use Illuminate\Support\Facades\Redis;
use MI\AzureManagedIdentity\Redis\AzureManagedIdentityPhpRedisConnector;
use Illuminate\Support\Facades\DB;
use MI\AzureManagedIdentity\Database\AzureManagedIdentityPostgresConnector;

class AzureServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../Config/azure-managed-identity.php', 'azure-managed-identity'
        );

        // Register the token service as singleton
        $this->app->singleton(AzureManagedIdentityTokenService::class, function ($app) {
            return new AzureManagedIdentityTokenService();
        });

        $this->app->bind('db.connector.pgsql', function ($app) {
            Log::info('Azure DB: Registering managed identity pgsql connector');

            return new AzureManagedIdentityPostgresConnector(
                $app->make(AzureManagedIdentityTokenService::class)
            );
        });

    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__.'/../config/azure-managed-identity.php' => config_path('azure-managed-identity.php'),
        ], 'azure-managed-identity-config');

        // Publish .env.example
        $this->publishes([
            __DIR__.'/../.env.example' => base_path('.env.azure.example'),
        ], 'azure-managed-identity-env');

        // Register Azure storage driver
        Storage::extend('azure', function ($app, $config) {
            $useManagedIdentity = !empty($config['use_managed_identity'])
                && filter_var($config['use_managed_identity'], FILTER_VALIDATE_BOOLEAN);

            if ($useManagedIdentity) {
                Log::info('Azure Storage: Using Managed Identity authentication');
                $client = $this->createBlobClientWithManagedIdentity($config);
            } else {
                Log::info('Azure Storage: Using Account Key authentication');
                $client = $this->createBlobClientWithAccountKey($config);
            }

            $adapter = new AzureBlobStorageAdapter(
                $client,
                $config['container']
            );

            $flysystem = new Filesystem($adapter);

            $url = $config['url'] ?? env('AZURE_STORAGE_URL', '');
            return new AzureAdapter(
                $flysystem,
                $adapter,
                [
                    'url' => $url,
                    'container' => $config['container'],
                    'visibility' => $config['visibility'] ?? 'public',
                ]
            );
        });

        Redis::extend('azure-mi', function () {
            Log::info('Azure Redis: Registering azure-mi Redis connector');
            return new AzureManagedIdentityPhpRedisConnector(
                app(AzureManagedIdentityTokenService::class)
            );
        });


    }

    /**
     * Create Blob client using Managed Identity
     */
    private function createBlobClientWithManagedIdentity(array $config): BlobRestProxy
    {
        $accountName = $config['name'];
        $clientId = $config['client_id'] ?? null;

        $tokenService = app(AzureManagedIdentityTokenService::class);
        $accessToken = $tokenService->getAccessToken($clientId);

        $blobClient = ManagedIdentityBlobRestProxy::createWithManagedIdentity(
            $accountName,
            $accessToken,
            [
                'timeout' => $config['timeout'] ?? 120,
                'connect_timeout' => $config['connect_timeout'] ?? 30,
            ]
        );

        Log::debug('Azure Storage: Blob client created with Managed Identity', [
            'account' => $accountName,
            'container' => $config['container'],
        ]);

        return $blobClient;
    }

    /**
     * Create Blob client using Account Key
     */
    private function createBlobClientWithAccountKey(array $config): BlobRestProxy
    {
        $connectionString = sprintf(
            'DefaultEndpointsProtocol=https;AccountName=%s;AccountKey=%s;EndpointSuffix=core.windows.net',
            $config['name'],
            $config['key']
        );

        Log::debug('Azure Storage: Blob client created with Account Key', [
            'account' => $config['name'],
            'container' => $config['container'],
        ]);

        return BlobRestProxy::createBlobService($connectionString);
    }
}

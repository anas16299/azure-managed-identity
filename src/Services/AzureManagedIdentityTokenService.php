<?php

namespace MI\AzureManagedIdentity\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AzureManagedIdentityTokenService
{
    private const METADATA_ENDPOINT = 'http://169.254.169.254/metadata/identity/oauth2/token';
    private const CACHE_PREFIX = 'azure_managed_identity_token';

    public function getAccessToken(?string $clientId = null, string $resourceKey = 'storage'): string
    {
        $cacheStore = $this->resolveCacheStore($resourceKey);
        $cacheKey = $this->getCacheKey($resourceKey, $clientId);

        $cachedToken = Cache::store($cacheStore)->get($cacheKey);

        if ($cachedToken) {
            Log::debug('Azure Managed Identity: Using cached token', [
                'resource_key' => $resourceKey,
                'cache_store' => $cacheStore,
            ]);
            return $cachedToken;
        }

        Log::info('Azure Managed Identity: Fetching new token from metadata endpoint', [
            'resource_key' => $resourceKey,
            'cache_store' => $cacheStore,
        ]);

        $token = $this->fetchTokenFromMetadata($clientId, $resourceKey);

        $this->cacheToken($cacheKey, $token, $cacheStore, $resourceKey);

        return $token['access_token'];
    }

    private function fetchTokenFromMetadata(?string $clientId, string $resourceKey): array
    {
        try {
            $resourceConfig = $this->getResourceConfig($resourceKey);

            $params = [
                'api-version' => $resourceConfig['api_version'],
                'resource' => $resourceConfig['resource'],
            ];

            if ($clientId) {
                $params['client_id'] = $clientId;
            }

            $timeout = config('azure-managed-identity.timeout', 10);

            Log::debug('Azure Managed Identity: Requesting token', [
                'resource_key' => $resourceKey,
                'resource' => $resourceConfig['resource'],
                'api_version' => $resourceConfig['api_version'],
                'has_client_id' => (bool) $clientId,
                'timeout' => $timeout,
            ]);

            $response = Http::timeout($timeout)
                ->withHeaders(['Metadata' => 'true'])
                ->get(self::METADATA_ENDPOINT, $params);

            if (!$response->successful()) {
                throw new \Exception('Failed to fetch managed identity token: ' . $response->body());
            }

            $data = $response->json();

            if (!isset($data['access_token']) || !isset($data['expires_in'])) {
                throw new \Exception('Invalid token response from metadata endpoint');
            }

            Log::info('Azure Managed Identity: Token fetched successfully', [
                'resource_key' => $resourceKey,
                'expires_in' => $data['expires_in'],
                'token_type' => $data['token_type'] ?? 'unknown',
            ]);

            return $data;

        } catch (\Exception $e) {
            Log::error('Azure Managed Identity: Failed to fetch token', [
                'resource_key' => $resourceKey,
                'error' => $e->getMessage(),
                'has_client_id' => (bool) $clientId,
            ]);

            throw new \Exception('Unable to authenticate with Azure Managed Identity: ' . $e->getMessage());
        }
    }

    private function cacheToken(string $cacheKey, array $tokenData, string $cacheStore, string $resourceKey): void
    {
        $buffer = config('azure-managed-identity.cache_buffer', 300);
        $ttl = max(($tokenData['expires_in'] ?? 3600) - $buffer, 60);

        Cache::store($cacheStore)->put($cacheKey, $tokenData['access_token'], $ttl);

        Log::debug('Azure Managed Identity: Token cached', [
            'resource_key' => $resourceKey,
            'cache_store' => $cacheStore,
            'ttl_seconds' => $ttl,
            'cache_key' => $cacheKey,
        ]);
    }

    private function getCacheKey(string $resourceKey, ?string $clientId): string
    {
        return self::CACHE_PREFIX . ':' . $resourceKey . ':' . ($clientId ?: 'system');
    }

    private function resolveCacheStore(string $resourceKey): string
    {
        $resourceStore = config("azure-managed-identity.resources.{$resourceKey}.cache_store");

        if (!empty($resourceStore)) {
            return $resourceStore;
        }

        return config('azure-managed-identity.cache_store', 'file');
    }

    private function getResourceConfig(string $resourceKey): array
    {
        $resource = config("azure-managed-identity.resources.{$resourceKey}.resource");
        $apiVersion = config("azure-managed-identity.resources.{$resourceKey}.api_version");

        if (empty($resource) || empty($apiVersion)) {
            throw new \Exception("Resource configuration missing for key: {$resourceKey}");
        }

        return [
            'resource' => $resource,
            'api_version' => $apiVersion,
        ];
    }

    public function clearCachedToken(?string $clientId = null, string $resourceKey = 'storage'): void
    {
        $cacheStore = $this->resolveCacheStore($resourceKey);
        $cacheKey = $this->getCacheKey($resourceKey, $clientId);

        Cache::store($cacheStore)->forget($cacheKey);

        Log::info('Azure Managed Identity: Cached token cleared', [
            'resource_key' => $resourceKey,
            'cache_store' => $cacheStore,
        ]);
    }
}

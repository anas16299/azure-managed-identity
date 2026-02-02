<?php

namespace MI\AzureManagedIdentity\Redis;

use Illuminate\Redis\Connectors\PhpRedisConnector;
use Illuminate\Support\Facades\Log;
use MI\AzureManagedIdentity\Services\AzureManagedIdentityTokenService;

class AzureManagedIdentityPhpRedisConnector extends PhpRedisConnector
{
    public function __construct(private readonly AzureManagedIdentityTokenService $tokenService)
    {
    }

    public function connect(array $config, array $options)
    {
        Log::info('Azure Redis: Starting connection', [
            'host' => $config['host'] ?? null,
            'port' => $config['port'] ?? null,
            'tls' => ($config['scheme'] ?? null) === 'tls',
            'use_managed_identity' => (bool) ($config['use_managed_identity'] ?? false),
        ]);

        if (!empty($config['use_managed_identity']) && filter_var($config['use_managed_identity'], FILTER_VALIDATE_BOOLEAN)) {
            $clientId = $config['client_id'] ?? null;

            $username = $config['username'] ?? $config['user'] ?? null;
            if (!$username) {
                Log::error('Azure Redis: Missing username for AAD auth');
                throw new \InvalidArgumentException('Azure Redis Managed Identity requires a username (AAD principal/object id).');
            }

            Log::info('Azure Redis: Fetching access token for redis resource', [
                'has_client_id' => (bool) $clientId,
            ]);

            $token = $this->tokenService->getAccessToken($clientId, 'redis');

            $config['password'] = $token;
            $config['username'] = $username;

            Log::info('Azure Redis: Token assigned to connection config', [
                'username' => $username,
                'token_length' => strlen($token),
            ]);
        }

        $client = parent::connect($config, $options);

        Log::info('Azure Redis: Connection created successfully');

        return $client;
    }
}

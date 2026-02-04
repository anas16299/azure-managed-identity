<?php

namespace MI\AzureManagedIdentity\Redis;

use Illuminate\Redis\Connectors\PhpRedisConnector;
use Illuminate\Support\Facades\Log;
use MI\AzureManagedIdentity\Services\AzureManagedIdentityTokenService;

class AzureManagedIdentityPhpRedisConnector extends PhpRedisConnector
{
    private AzureManagedIdentityTokenService $tokenService;

    public function __construct(AzureManagedIdentityTokenService $tokenService)
    {
        $this->tokenService = $tokenService;
    }


    public function connect(array $config, array $options)
    {
        \Log::info('Azure Redis: Starting connection', [
            'host' => $config['host'] ?? null,
            'port' => $config['port'] ?? null,
            'tls'  => (($config['scheme'] ?? null) === 'tls'),
            'use_managed_identity' => (bool) ($config['use_managed_identity'] ?? false),
            'has_url' => array_key_exists('url', $config),
            'url_value' => $config['url'] ?? null,
        ]);

        // avoid url side-effects
        if (array_key_exists('url', $config) && empty($config['url'])) {
            unset($config['url']);
        }

        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? 6379);

        $timeout = (float) ($config['timeout'] ?? 10);
        $readTimeout = (float) ($config['read_timeout'] ?? 10);

        $isTls = (($config['scheme'] ?? null) === 'tls');
        $redisHost = $isTls ? "tls://{$host}" : $host;

        // Managed Identity token
        if (!empty($config['use_managed_identity']) && filter_var($config['use_managed_identity'], FILTER_VALIDATE_BOOLEAN)) {
            $username = $config['username'] ?? null;
            $clientId = $config['client_id'] ?? null;

            if (!$username) {
                \Log::error('Azure Redis: Missing username for AAD auth');
                throw new \InvalidArgumentException('Azure Redis Managed Identity requires AZURE_REDIS_USERNAME.');
            }

            $token = $this->tokenService->getAccessToken($clientId, 'redis');

            $config['password'] = (string) $token;
            $config['username'] = (string) $username;

            \Log::info('Azure Redis: Token fetched for MI', [
                'username' => $config['username'],
                'token_length' => strlen($config['password']),
            ]);
        }

        $password = $config['password'] ?? null;
        $username = $config['username'] ?? null;

        $client = new \Redis();
        $context = $config['context'] ?? null;

        // connect
        $client->connect($redisHost, $port, $timeout, null, 0, $readTimeout, $context);

        // IMPORTANT: ACL auth username + token
        if (!empty($password)) {
            if (!empty($username)) {
                $client->auth([(string) $username, (string) $password]);
            } else {
                $client->auth((string) $password);
            }
        }

        // select DB AFTER auth
        if (isset($config['database'])) {
            $client->select((int) $config['database']);
        }

        // prefix support
        $prefix = $config['options']['prefix'] ?? ($config['prefix'] ?? null);
        if (!empty($prefix)) {
            $client->setOption(\Redis::OPT_PREFIX, (string) $prefix);
        }

        \Log::info('Azure Redis: Connection created successfully');

        // ✅ Return Laravel Connection wrapper (NOT raw Redis)
        return new \Illuminate\Redis\Connections\PhpRedisConnection(
            $client,
            null,
            $config
        );
    }
}
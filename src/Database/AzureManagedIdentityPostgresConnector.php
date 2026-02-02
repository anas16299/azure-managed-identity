<?php

namespace CleverSo\AzureManagedIdentity\Database;

use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Support\Facades\Log;
use CleverSo\AzureManagedIdentity\Services\AzureManagedIdentityTokenService;
use PDO;

class AzureManagedIdentityPostgresConnector extends PostgresConnector
{
    public function __construct(
        private readonly AzureManagedIdentityTokenService $tokenService
    ) {}

    public function connect(array $config): PDO
    {
        $useManagedIdentity = !empty($config['use_managed_identity'])
            && filter_var($config['use_managed_identity'], FILTER_VALIDATE_BOOLEAN);

        Log::info('Azure DB: Starting pgsql connection', [
            'host' => $config['host'] ?? null,
            'port' => $config['port'] ?? null,
            'database' => $config['database'] ?? null,
            'username' => $config['username'] ?? null,
            'sslmode' => $config['sslmode'] ?? null,
            'use_managed_identity' => $useManagedIdentity,
        ]);

        if ($useManagedIdentity) {
            $clientId = $config['client_id'] ?? null;

            Log::info('Azure DB: Fetching access token for db resource', [
                'has_client_id' => (bool) $clientId,
            ]);

            $token = $this->tokenService->getAccessToken($clientId, 'db');

            $config['password'] = $token;

            if (empty($config['sslmode'])) {
                $config['sslmode'] = 'require';
            }

            Log::info('Azure DB: Token assigned to pgsql connection config', [
                'token_length' => strlen($token),
                'sslmode' => $config['sslmode'],
            ]);
        }

        $pdo = parent::connect($config);

        Log::info('Azure DB: pgsql connection created successfully', [
            'use_managed_identity' => $useManagedIdentity,
        ]);

        return $pdo;
    }
}

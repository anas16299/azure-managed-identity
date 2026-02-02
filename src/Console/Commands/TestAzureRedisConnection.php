<?php

namespace CleverSo\AzureManagedIdentity\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class TestAzureRedisConnection extends Command
{
    protected $signature = 'azure:redis-test {--connection=default : Redis connection name}';
    protected $description = 'Test Azure Redis connection with Managed Identity';

    public function handle()
    {
        $connectionName = $this->option('connection');

        $this->info("Testing Azure Redis Connection: {$connectionName}");
        Log::info('Azure Redis Test: Starting', ['connection' => $connectionName]);

        try {
            $redis = Redis::connection($connectionName);

            $pong = $redis->ping();
            $this->info("PING: " . (string) $pong);
            Log::info('Azure Redis Test: PING success', ['pong' => (string) $pong]);

            $key = 'azure_mi_test_key';
            $value = 'ok_' . time();

            $redis->set($key, $value);
            $fetched = $redis->get($key);

            $this->info("SET/GET: {$fetched}");
            Log::info('Azure Redis Test: SET/GET success', [
                'key' => $key,
                'value' => $fetched,
            ]);

            $this->info('All tests passed!');
            Log::info('Azure Redis Test: Completed successfully');

            return 0;

        } catch (\Throwable $e) {
            $this->error('Test failed: ' . $e->getMessage());

            Log::error('Azure Redis Test: Failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return 1;
        }
    }
}

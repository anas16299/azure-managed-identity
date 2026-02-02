<?php

namespace CleverSo\AzureManagedIdentity\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use CleverSo\AzureManagedIdentity\Services\AzureManagedIdentityTokenService;

class TestAzureConnection extends Command
{
    protected $signature = 'azure:test {--disk=azure : The disk to test}';
    protected $description = 'Test Azure Storage connection with Managed Identity';

    public function handle()
    {
        $diskName = $this->option('disk');

        $this->info("Testing Azure Storage Connection for disk: {$diskName}");

        try {
            $config = config("filesystems.disks.{$diskName}");

            if (!$config) {
                $this->error("Disk '{$diskName}' not found in config/filesystems.php");
                return 1;
            }

            // Test token fetch
            if ($config['use_managed_identity'] ?? false) {
                $this->info('Using Managed Identity authentication');
                $tokenService = app(AzureManagedIdentityTokenService::class);
                $clientId = $config['client_id'] ?? null;
                $token = $tokenService->getAccessToken($clientId);
                $this->info('✓ Token fetched successfully');

                // Test direct API call
                $this->info('Testing direct Azure API call...');
                $accountName = $config['name'];
                $container = $config['container'];

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'x-ms-version' => '2021-08-06',
                ])->get("https://{$accountName}.blob.core.windows.net/{$container}?restype=container&comp=list&maxresults=10");

                if ($response->successful()) {
                    $this->info('✓ Direct API call successful');
                    $this->info('Response status: ' . $response->status());
                } else {
                    $this->error('✗ Direct API call failed');
                    $this->error('Status: ' . $response->status());
                    $this->error('Body: ' . $response->body());
                    return 1;
                }
            } else {
                $this->info('Using Account Key authentication');
            }

            // Test storage connection via Laravel Storage
            $this->info('Testing Laravel Storage facade...');
            $disk = Storage::disk($diskName);

            // Try to list files
            $files = $disk->files();
            $this->info('✓ Storage connection successful');
            $this->info('Files in container: ' . count($files));

            $this->info(' All tests passed!');
            return 0;

        } catch (\Exception $e) {
            $this->error(' Test failed: ' . $e->getMessage());
            $this->error('File: ' . $e->getFile());
            $this->error('Line: ' . $e->getLine());
            return 1;
        }
    }
}

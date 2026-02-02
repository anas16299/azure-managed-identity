<?php

namespace CleverSo\AzureManagedIdentity\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;

class ManagedIdentityBlobRestProxy
{
    /**
     * Create a blob service instance with managed identity token
     */
    public static function createWithManagedIdentity(
        string $accountName,
        string $accessToken,
        array $options = []
    ): BlobRestProxy {
        $blobEndpoint = sprintf('https://%s.blob.core.windows.net/', $accountName);

        // Create a minimal connection string
        $connectionString = sprintf(
            'BlobEndpoint=%s;SharedAccessSignature=sv=2021-08-06',
            $blobEndpoint
        );

        // Create the blob client using the SDK's factory method
        $blobClient = BlobRestProxy::createBlobService($connectionString);

        // Inject our custom Guzzle client with Bearer token
        self::injectBearerTokenClient($blobClient, $accessToken, $options);

        return $blobClient;
    }

    /**
     * Inject custom Guzzle client with Bearer token into existing BlobRestProxy
     */
    private static function injectBearerTokenClient(
        BlobRestProxy $blobClient,
        string $accessToken,
        array $options
    ): void {
        $stack = HandlerStack::create();

        // Add request middleware
        $stack->push(Middleware::mapRequest(function (RequestInterface $request) use ($accessToken) {
            Log::debug('Azure Storage Request', [
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
            ]);

            $uri = $request->getUri();

            // Remove any SAS token parameters
            $query = $uri->getQuery();
            if ($query) {
                parse_str($query, $params);
                $sasParams = ['sv', 'se', 'sr', 'sp', 'sig', 'st', 'spr', 'sip', 'rscc', 'rscd', 'rsce', 'rsch', 'rsct'];
                foreach ($sasParams as $param) {
                    unset($params[$param]);
                }
                $newQuery = http_build_query($params);
                $uri = $uri->withQuery($newQuery);
            }

            return $request
                ->withUri($uri)
                ->withoutHeader('Authorization')
                ->withHeader('Authorization', 'Bearer ' . $accessToken)
                ->withHeader('x-ms-version', '2021-08-06');
        }));

        // Add response middleware
        $stack->push(Middleware::mapResponse(function (ResponseInterface $response) {
            Log::debug('Azure Storage Response', [
                'status' => $response->getStatusCode(),
            ]);
            return $response;
        }));

        $guzzleClient = new Client([
            'handler' => $stack,
            'timeout' => $options['timeout'] ?? 120,
            'connect_timeout' => $options['connect_timeout'] ?? 30,
            'http_errors' => false,
        ]);

        // Inject using reflection
        try {
            $reflection = new \ReflectionClass($blobClient);
            $propertyNames = ['httpClient', 'client', '_httpClient', 'guzzleClient'];

            while ($reflection) {
                foreach ($propertyNames as $propName) {
                    try {
                        $clientProperty = $reflection->getProperty($propName);
                        $clientProperty->setAccessible(true);
                        $existingClient = $clientProperty->getValue($blobClient);

                        if ($existingClient instanceof Client || is_object($existingClient)) {
                            if (!($existingClient instanceof Client)) {
                                $innerReflection = new \ReflectionClass($existingClient);
                                foreach (['client', 'guzzleClient', 'httpClient'] as $innerPropName) {
                                    try {
                                        $innerProperty = $innerReflection->getProperty($innerPropName);
                                        $innerProperty->setAccessible(true);
                                        $innerProperty->setValue($existingClient, $guzzleClient);
                                        Log::info('Azure Storage: Successfully injected Bearer token into inner HTTP client', [
                                            'wrapper_class' => get_class($existingClient),
                                            'property' => $innerPropName,
                                        ]);
                                        return;
                                    } catch (\ReflectionException $e) {
                                        continue;
                                    }
                                }
                            } else {
                                $clientProperty->setValue($blobClient, $guzzleClient);
                                Log::info('Azure Storage: Successfully injected Bearer token HTTP client', [
                                    'property' => $propName,
                                ]);
                                return;
                            }
                        }
                    } catch (\ReflectionException $e) {
                        continue;
                    }
                }
                $reflection = $reflection->getParentClass();
            }

            Log::error('Azure Storage: Could not find HTTP client property to inject');

        } catch (\Exception $e) {
            Log::error('Azure Storage: Failed to inject HTTP client', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}

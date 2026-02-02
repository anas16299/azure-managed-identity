<?php

return [
    'cache_store' => env('AZURE_MI_CACHE_STORE', 'file'),
    'cache_buffer' => env('AZURE_MI_CACHE_BUFFER', 300),
    'timeout' => env('AZURE_MI_TIMEOUT', 10),

    'resources' => [
        'storage' => [
            'resource' => env('AZURE_MI_STORAGE_RESOURCE', 'https://storage.azure.com/'),
            'api_version' => env('AZURE_MI_STORAGE_API_VERSION', '2018-02-01'),
            'cache_store' => env('AZURE_MI_STORAGE_CACHE_STORE', null),
        ],
        'redis' => [
            'resource' => env('AZURE_MI_REDIS_RESOURCE', 'https://redis.azure.com'),
            'api_version' => env('AZURE_MI_REDIS_API_VERSION', '2019-08-01'),
            'cache_store' => env('AZURE_MI_REDIS_CACHE_STORE', null),
        ],

        'db' => [
            'resource' => env('AZURE_MI_DB_RESOURCE', 'https://ossrdbms-aad.database.windows.net'),
            'api_version' => env('AZURE_MI_DB_API_VERSION', '2019-08-01'),
            'cache_store' => env('AZURE_MI_DB_CACHE_STORE', null),
        ],
    ],
];

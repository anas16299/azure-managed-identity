<?php

namespace MI\AzureManagedIdentity\Filesystem;

use Illuminate\Filesystem\FilesystemAdapter;

class AzureAdapter extends FilesystemAdapter
{
    protected $customConfig;

    public function __construct($driver, $adapter, array $config)
    {
        parent::__construct($driver, $adapter);
        $this->customConfig = $config;
    }

    public function url($path)
    {
        $base = rtrim($this->customConfig['url'] ?? '', '/');
        $container = trim($this->customConfig['container'] ?? '', '/');

        return "{$base}/{$container}/" . ltrim($path, '/');
    }
}

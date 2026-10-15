<?php

declare(strict_types=1);

namespace App\Services\RouterOS;

use App\Domain\Router\Models\Router;
use App\Services\RouterOS\Adapters\LibraryRouterOsClient;
use App\Services\RouterOS\Adapters\MockRouterOsClient;
use App\Services\RouterOS\Contracts\RouterOsClient;
use App\Services\RouterOS\Support\ConnectionConfig;
use InvalidArgumentException;

/**
 * Builds a configured RouterOsClient for a given router, selecting the
 * implementation from config('mikweb.routeros.driver'). This is the single
 * seam between "which wire protocol" and the rest of the app.
 */
class RouterOsClientFactory
{
    public function for(Router $router): RouterOsClient
    {
        return $this->make()->configure(ConnectionConfig::forRouter($router));
    }

    public function make(?string $driver = null): RouterOsClient
    {
        $driver ??= (string) config('mikweb.routeros.driver', 'library');

        return match ($driver) {
            'library' => new LibraryRouterOsClient,
            'mock' => new MockRouterOsClient,
            default => throw new InvalidArgumentException("Unknown RouterOS driver [{$driver}]."),
        };
    }
}

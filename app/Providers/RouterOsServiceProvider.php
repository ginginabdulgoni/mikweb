<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\RouterOS\ConnectionManager;
use App\Services\RouterOS\RouterOsClientFactory;
use App\Services\RouterOS\Support\CircuitBreaker;
use App\Services\SNMP\SnmpService;
use Illuminate\Support\ServiceProvider;

class RouterOsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CircuitBreaker::class, fn () => CircuitBreaker::make());
        $this->app->singleton(RouterOsClientFactory::class);
        $this->app->singleton(ConnectionManager::class);
        $this->app->singleton(SnmpService::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\RouterOS\Support;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Cache as CacheFacade;

/**
 * Per-router circuit breaker. After N consecutive failures the circuit opens
 * and stays open for a cooldown window, during which calls fail fast instead
 * of repeatedly hammering an unreachable device. A single success closes it.
 *
 * State lives in the cache (Redis in production) so it is shared across all
 * workers and web processes.
 */
final class CircuitBreaker
{
    public function __construct(private readonly Cache $cache) {}

    public static function make(): self
    {
        $store = config('mikweb.circuit_breaker.cache_store');

        return new self(CacheFacade::store($store));
    }

    public function isOpen(int $routerId): bool
    {
        if (! config('mikweb.circuit_breaker.enabled', true)) {
            return false;
        }

        return (bool) $this->cache->get($this->openKey($routerId), false);
    }

    public function recordSuccess(int $routerId): void
    {
        $this->cache->forget($this->failKey($routerId));
        $this->cache->forget($this->openKey($routerId));
    }

    public function recordFailure(int $routerId): void
    {
        if (! config('mikweb.circuit_breaker.enabled', true)) {
            return;
        }

        $threshold = (int) config('mikweb.circuit_breaker.failure_threshold', 5);
        $cooldown = (int) config('mikweb.circuit_breaker.cooldown_seconds', 30);

        $failures = (int) $this->cache->get($this->failKey($routerId), 0) + 1;
        // Track the running failure count for a generous window.
        $this->cache->put($this->failKey($routerId), $failures, max($cooldown * 2, 60));

        if ($failures >= $threshold) {
            $this->cache->put($this->openKey($routerId), true, $cooldown);
        }
    }

    public function reset(int $routerId): void
    {
        $this->recordSuccess($routerId);
    }

    private function failKey(int $routerId): string
    {
        return "mikweb:cb:{$routerId}:failures";
    }

    private function openKey(int $routerId): string
    {
        return "mikweb:cb:{$routerId}:open";
    }
}

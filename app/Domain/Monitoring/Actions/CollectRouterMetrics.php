<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Actions;

use App\Domain\Monitoring\Models\RouterMetric;
use App\Domain\Router\Enums\RouterStatus;
use App\Domain\Router\Models\Router;
use App\Services\RouterOS\ConnectionManager;
use App\Services\RouterOS\Contracts\RouterOsClient;
use App\Services\RouterOS\Exceptions\RouterOsException;

/**
 * Polls one router's /system/resource and records a metrics sample. Also keeps
 * the router row's live status fresh. Never throws — an unreachable device
 * yields an "unreachable" sample so gaps are visible in the UI.
 */
class CollectRouterMetrics
{
    public function __construct(private readonly ConnectionManager $connections) {}

    public function execute(Router $router): RouterMetric
    {
        $start = microtime(true);

        try {
            $router->loadMissing('credentials');
            $resource = $this->connections->run(
                $router,
                fn (RouterOsClient $client) => $client->query('/system/resource/print')[0] ?? [],
            );

            $latency = (int) round((microtime(true) - $start) * 1000);
            $metric = $this->store($router, $resource, reachable: true, latency: $latency);

            $router->forceFill([
                'status' => RouterStatus::Online,
                'last_seen_at' => now(),
                'last_error' => null,
            ])->save();

            return $metric;
        } catch (RouterOsException $e) {
            $router->forceFill([
                'status' => RouterStatus::Unreachable,
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
            ])->save();

            return $this->store($router, [], reachable: false, latency: null);
        }
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function store(Router $router, array $resource, bool $reachable, ?int $latency): RouterMetric
    {
        $total = (int) ($resource['total-memory'] ?? 0);
        $free = (int) ($resource['free-memory'] ?? 0);
        $diskTotal = (int) ($resource['total-hdd-space'] ?? 0);
        $diskFree = (int) ($resource['free-hdd-space'] ?? 0);

        return RouterMetric::create([
            'router_id' => $router->id,
            'cpu_load' => isset($resource['cpu-load']) ? (int) $resource['cpu-load'] : null,
            'mem_used' => $total > 0 ? max(0, $total - $free) : null,
            'mem_total' => $total ?: null,
            'disk_used' => $diskTotal > 0 ? max(0, $diskTotal - $diskFree) : null,
            'disk_total' => $diskTotal ?: null,
            'uptime' => $resource['uptime'] ?? null,
            'reachable' => $reachable,
            'latency_ms' => $latency,
            'recorded_at' => now(),
        ]);
    }
}

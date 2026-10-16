<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Monitoring\Actions\CollectRouterMetrics;
use App\Domain\Monitoring\Models\RouterMetric;
use App\Domain\Router\Enums\RouterStatus;
use App\Domain\Router\Models\Router;
use App\Jobs\CollectRouterMetricsJob;
use App\Services\RouterOS\ConnectionManager;
use App\Services\RouterOS\Contracts\RouterOsClient;
use App\Support\Access\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MonitoringController extends Controller
{
    public function index(Request $request, CollectRouterMetrics $collector): Response
    {
        $this->authorize(Permissions::MONITORING_VIEW);

        $orgId = $request->user()->organization_id;
        $routers = Router::query()->where('organization_id', $orgId)->orderBy('name')->get();

        // Latest sample per router.
        $latest = RouterMetric::query()
            ->whereIn('router_id', $routers->pluck('id'))
            ->whereIn('id', function ($q) use ($routers) {
                $q->selectRaw('MAX(id)')->from('router_metrics')
                    ->whereIn('router_id', $routers->pluck('id'))->groupBy('router_id');
            })
            ->get()
            ->keyBy('router_id');

        $selectedIdsInput = $request->input('routers');
        if (is_string($selectedIdsInput)) {
            $selectedIds = array_values(array_filter(array_map('intval', explode(',', $selectedIdsInput))));
        } elseif (is_array($selectedIdsInput)) {
            $selectedIds = array_values(array_filter(array_map('intval', $selectedIdsInput)));
        } else {
            $selectedIds = [];
        }

        if (empty($selectedIds)) {
            $primaryId = (int) ($request->integer('router') ?: ($routers->first()->id ?? 0));
            $selectedIds = $primaryId > 0 ? [$primaryId] : [];
        }

        $selectedIds = $routers->whereIn('id', $selectedIds)->pluck('id')->values()->all();

        // If a selected router has never had metrics collected yet, run a sample so uptime/hardware isn't blank
        foreach ($routers->whereIn('id', $selectedIds) as $r) {
            if (! $latest->has($r->id) && $r->status === RouterStatus::Online) {
                try {
                    $r->loadMissing('credentials');
                    $m = $collector->execute($r);
                    $latest->put($r->id, $m);
                } catch (\Throwable) {
                    // ignore
                }
            }
        }
        $selectedId = $selectedIds[0] ?? (int) ($routers->first()->id ?? 0);
        $range = $request->string('range')->toString() ?: '6h';
        $tab = $request->string('tab')->toString() ?: 'traffic';

        $since = match ($range) {
            '1h' => now()->subHour(),
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            default => now()->subHours(6),
        };
        $timeFormat = $range === '7d' ? 'd/m H:i' : 'H:i';

        $series = RouterMetric::query()
            ->whereIn('router_id', $selectedIds)
            ->where('recorded_at', '>=', $since)
            ->orderBy('recorded_at')
            ->limit(1000)
            ->get();

        $primarySeries = $series->where('router_id', $selectedId)->values();

        $onlineCount = $routers->where('status', RouterStatus::Online)->count();
        $unreachableCount = $routers->where('status', RouterStatus::Unreachable)->count();
        $metricValues = $latest->filter();
        $avgCpu = $metricValues->avg('cpu_load');
        $avgMem = $metricValues->map(fn ($m) => $m->memoryPercent())->filter()->avg();
        $avgLatency = $metricValues->avg('latency_ms');

        $kpis = [
            'total' => $routers->count(),
            'online' => $onlineCount,
            'unreachable' => $unreachableCount,
            'avg_cpu' => $avgCpu !== null ? (int) round($avgCpu) : null,
            'avg_mem' => $avgMem !== null ? (int) round($avgMem) : null,
            'avg_latency' => $avgLatency !== null ? (int) round($avgLatency) : null,
        ];

        $selectedRouterModel = $routers->firstWhere('id', $selectedId);

        return Inertia::render('Monitoring/Index', [
            'routers' => $routers->map(function (Router $r) use ($latest) {
                $m = $latest->get($r->id);

                return [
                    'id' => $r->id,
                    'name' => $r->name,
                    'host' => $r->host,
                    'status' => $r->status->value,
                    'status_color' => $r->status->color(),
                    'cpu_load' => $m?->cpu_load,
                    'mem_percent' => $m?->memoryPercent(),
                    'mem_used' => $m?->mem_used,
                    'mem_total' => $m?->mem_total,
                    'disk_used' => $m?->disk_used,
                    'disk_total' => $m?->disk_total,
                    'uptime' => $m?->uptime,
                    'latency_ms' => $m?->latency_ms,
                    'recorded_at' => $m?->recorded_at?->diffForHumans(),
                    'hardware_model' => $r->hardware_model ?: ($r->board_name ?: 'MikroTik Router'),
                    'routeros_version' => $r->routeros_version ?: 'RouterOS',
                    'architecture' => $r->architecture ?: 'mips/arm',
                ];
            })->values(),
            'selectedRouter' => $selectedId,
            'selectedRouters' => $selectedIds,
            'selectedRouterName' => $selectedRouterModel?->name ?? 'Router',
            'range' => $range,
            'initialTab' => $tab,
            'kpis' => $kpis,
            'chart' => [
                'labels' => $primarySeries->map(fn ($m) => $m->recorded_at?->format($timeFormat))->values(),
                'cpu' => $primarySeries->map(fn ($m) => $m->cpu_load)->values(),
                'mem' => $primarySeries->map(fn ($m) => $m->memoryPercent())->values(),
                'latency' => $primarySeries->map(fn ($m) => $m->latency_ms)->values(),
            ],
        ]);
    }

    /** Trigger an immediate collection pass (dispatches queued jobs). */
    public function collect(Request $request): RedirectResponse
    {
        $this->authorize(Permissions::MONITORING_VIEW);

        Router::query()
            ->where('organization_id', $request->user()->organization_id)
            ->where('monitoring_enabled', true)
            ->pluck('id')
            ->each(fn ($id) => CollectRouterMetricsJob::dispatch((int) $id));

        return back()->with('success', 'Metrics collection queued for all monitored routers.');
    }

    /** Trigger immediate synchronous metric collection for one router. */
    public function collectRouter(Request $request, Router $router, CollectRouterMetrics $collector): RedirectResponse
    {
        $this->authorize(Permissions::MONITORING_VIEW);
        abort_unless($router->organization_id === $request->user()->organization_id, 403);

        try {
            $router->loadMissing('credentials');
            $metric = $collector->execute($router);

            return back()->with('success', "Metrics refreshed for '{$router->name}' ({$metric->latency_ms}ms).");
        } catch (\Throwable $e) {
            return back()->with('error', "Could not collect metrics from '{$router->name}': ".$e->getMessage());
        }
    }

    /**
     * Polling endpoint for fleet statistics (Resources, Uptime, PPPoE & Hotspot counts).
     * Fetches live metrics directly from MikroTik RouterOS for each requested router.
     */
    public function fleetStats(Request $request, ConnectionManager $connections): JsonResponse
    {
        $this->authorize(Permissions::MONITORING_VIEW);

        // Release session file lock immediately so concurrent navigation / other tabs do not block
        $request->session()->save();

        $orgId = $request->user()->organization_id;

        $routerIdsInput = $request->input('routers');
        if (is_string($routerIdsInput)) {
            $routerIds = array_values(array_filter(array_map('intval', explode(',', $routerIdsInput))));
        } elseif (is_array($routerIdsInput)) {
            $routerIds = array_values(array_filter(array_map('intval', $routerIdsInput)));
        } else {
            $routerIds = [];
        }

        $routers = Router::query()
            ->where('organization_id', $orgId)
            ->when(! empty($routerIds), fn ($q) => $q->whereIn('id', $routerIds))
            ->get();

        $stats = [];

        foreach ($routers as $router) {
            $router->loadMissing('credentials');

            try {
                $start = microtime(true);

                $data = $connections->run($router, function (RouterOsClient $client): array {
                    $resource = $client->query('/system/resource/print')[0] ?? [];

                    $pppActive = [];
                    $pppSecret = [];
                    $hotspotActive = [];
                    $hotspotUser = [];

                    try {
                        $pppActive = $client->query('/ppp/active/print');
                    } catch (\Throwable) {
                    }

                    try {
                        $pppSecret = $client->query('/ppp/secret/print');
                    } catch (\Throwable) {
                    }

                    try {
                        $hotspotActive = $client->query('/ip/hotspot/active/print');
                    } catch (\Throwable) {
                    }

                    try {
                        $hotspotUser = $client->query('/ip/hotspot/user/print');
                    } catch (\Throwable) {
                    }

                    return [
                        'resource' => $resource,
                        'ppp_active' => count($pppActive),
                        'ppp_secrets' => count($pppSecret),
                        'hotspot_active' => count($hotspotActive),
                        'hotspot_users' => count($hotspotUser),
                    ];
                });

                $latency = (int) round((microtime(true) - $start) * 1000);
                $resource = $data['resource'];

                $total = (int) ($resource['total-memory'] ?? 0);
                $free = (int) ($resource['free-memory'] ?? 0);
                $diskTotal = (int) ($resource['total-hdd-space'] ?? 0);
                $diskFree = (int) ($resource['free-hdd-space'] ?? 0);
                $cpuLoad = isset($resource['cpu-load']) ? (int) $resource['cpu-load'] : null;
                $memUsed = $total > 0 ? max(0, $total - $free) : null;
                $diskUsed = $diskTotal > 0 ? max(0, $diskTotal - $diskFree) : null;
                $uptime = $resource['uptime'] ?? null;
                $memPercent = ($total > 0 && $memUsed !== null) ? (int) round(($memUsed / $total) * 100) : null;

                // Also store RouterMetric to keep DB time-series alive
                RouterMetric::create([
                    'router_id' => $router->id,
                    'cpu_load' => $cpuLoad,
                    'mem_used' => $memUsed,
                    'mem_total' => $total ?: null,
                    'disk_used' => $diskUsed,
                    'disk_total' => $diskTotal ?: null,
                    'uptime' => $uptime,
                    'reachable' => true,
                    'latency_ms' => $latency,
                    'recorded_at' => now(),
                ]);

                $router->forceFill([
                    'status' => RouterStatus::Online,
                    'last_seen_at' => now(),
                    'last_error' => null,
                ])->save();

                $stats[$router->id] = [
                    'id' => $router->id,
                    'status' => 'online',
                    'status_color' => 'emerald',
                    'uptime' => $uptime,
                    'cpu_load' => $cpuLoad,
                    'mem_percent' => $memPercent,
                    'mem_used' => $memUsed,
                    'mem_total' => $total ?: null,
                    'disk_used' => $diskUsed,
                    'disk_total' => $diskTotal ?: null,
                    'latency_ms' => $latency,
                    'recorded_at' => 'Baru saja',
                    'ppp' => [
                        'active' => $data['ppp_active'],
                        'secrets' => $data['ppp_secrets'],
                    ],
                    'hotspot' => [
                        'active' => $data['hotspot_active'],
                        'users' => $data['hotspot_users'],
                    ],
                ];
            } catch (\Throwable $e) {
                $stats[$router->id] = [
                    'id' => $router->id,
                    'status' => $router->status->value,
                    'status_color' => $router->status->color(),
                    'uptime' => null,
                    'cpu_load' => null,
                    'mem_percent' => null,
                    'mem_used' => null,
                    'mem_total' => null,
                    'disk_used' => null,
                    'disk_total' => null,
                    'latency_ms' => null,
                    'recorded_at' => 'Offline / Gagal',
                    'error' => $e->getMessage(),
                    'ppp' => [
                        'active' => 0,
                        'secrets' => 0,
                    ],
                    'hotspot' => [
                        'active' => 0,
                        'users' => 0,
                    ],
                ];
            }
        }

        return response()->json([
            'success' => true,
            'routers' => $stats,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}

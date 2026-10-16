<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Monitoring\Actions\CollectRouterMetrics;
use App\Domain\Monitoring\Models\RouterMetric;
use App\Domain\Router\Enums\RouterStatus;
use App\Domain\Router\Models\Router;
use App\Jobs\CollectRouterMetricsJob;
use App\Support\Access\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MonitoringController extends Controller
{
    public function index(Request $request): Response
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
                    'latency_ms' => $m?->latency_ms,
                    'recorded_at' => $m?->recorded_at?->diffForHumans(),
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
}

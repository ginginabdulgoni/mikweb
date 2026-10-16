<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Monitoring\Models\RouterMetric;
use App\Domain\Router\Enums\RouterStatus;
use App\Domain\Router\Models\Router;
use App\Support\Access\Permissions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NetworkMapController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize(Permissions::MONITORING_VIEW);

        $orgId = $request->user()->organization_id;
        $routers = Router::where('organization_id', $orgId)->orderBy('name')->get();

        // Get latest metric per router for CPU, RAM, and Latency
        $latest = RouterMetric::query()
            ->whereIn('router_id', $routers->pluck('id'))
            ->whereIn('id', function ($q) use ($routers) {
                $q->selectRaw('MAX(id)')->from('router_metrics')
                    ->whereIn('router_id', $routers->pluck('id'))->groupBy('router_id');
            })
            ->get()
            ->keyBy('router_id');

        $nodes = [
            [
                'id' => 'internet_cloud',
                'name' => 'Internet / Upstream',
                'symbol' => 'roundRect',
                'symbolSize' => [120, 48],
                'itemStyle' => ['color' => '#3b82f6'],
                'category' => 0,
                'value' => 'Global WAN Gateway',
                'status' => 'online',
            ],
        ];

        $links = [];

        foreach ($routers as $idx => $r) {
            $m = $latest->get($r->id);
            $isOnline = $r->status === RouterStatus::Online;

            $color = match ($r->status) {
                RouterStatus::Online => '#10b981',
                RouterStatus::Unreachable => '#f43f5e',
                default => '#71717a',
            };

            $nodes[] = [
                'id' => "router_{$r->id}",
                'name' => $r->name,
                'symbol' => 'circle',
                'symbolSize' => 52,
                'itemStyle' => ['color' => $color],
                'category' => 1,
                'routerId' => $r->id,
                'host' => $r->host,
                'status' => $r->status->value,
                'cpu' => $m?->cpu_load,
                'mem' => $m?->memoryPercent(),
                'latency' => $m?->latency_ms,
                'model' => $r->board_name ?: $r->hardware_model ?: 'RouterBOARD',
            ];

            // Connect first router or Core router to Internet Upstream
            if ($idx === 0) {
                $links[] = [
                    'source' => 'internet_cloud',
                    'target' => "router_{$r->id}",
                    'lineStyle' => ['color' => $color, 'width' => 3],
                    'label' => ['show' => true, 'formatter' => 'WAN Uplink'],
                ];
            } else {
                // Link subsequent edge routers to core router or internet
                $parentTarget = "router_{$routers[0]->id}";
                $links[] = [
                    'source' => $parentTarget,
                    'target' => "router_{$r->id}",
                    'lineStyle' => ['color' => $color, 'width' => 2, 'type' => 'dashed'],
                    'label' => ['show' => true, 'formatter' => $m?->latency_ms ? "{$m->latency_ms}ms" : 'Trunk'],
                ];
            }
        }

        return Inertia::render('NetworkMap/Index', [
            'nodes' => $nodes,
            'links' => $links,
            'summary' => [
                'total' => $routers->count(),
                'online' => $routers->where('status', RouterStatus::Online)->count(),
                'unreachable' => $routers->where('status', RouterStatus::Unreachable)->count(),
            ],
        ]);
    }
}

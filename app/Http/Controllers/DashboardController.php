<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Router\Enums\RouterStatus;
use App\Domain\Router\Models\Router;
use App\Services\RouterOS\Exceptions\RouterOsException;
use App\Services\RouterOS\ResourceClient;
use App\Support\Access\Permissions;
use App\Support\Access\RouterAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ResourceClient $client): Response|JsonResponse
    {
        $orgId = $request->user()->organization_id;
        $base = Router::query()->where('organization_id', $orgId);

        // 1. Fleet overview counts (Database only — 0 MikroTik load)
        $counts = [
            'total' => (clone $base)->count(),
            'online' => (clone $base)->where('status', RouterStatus::Online->value)->count(),
            'offline' => (clone $base)->where('status', RouterStatus::Offline->value)->count(),
            'unreachable' => (clone $base)->where('status', RouterStatus::Unreachable->value)->count(),
            'unknown' => (clone $base)->where('status', RouterStatus::Unknown->value)->count(),
        ];

        $routers = (clone $base)
            ->select(['id', 'name', 'host', 'status', 'routeros_version', 'last_seen_at'])
            ->orderBy('name')
            ->get()
            ->map(fn (Router $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'host' => $r->host,
                'status' => $r->status->value,
                'status_label' => $r->status->label(),
                'status_color' => $r->status->color(),
                'routeros_version' => $r->routeros_version,
                'last_seen_at' => $r->last_seen_at?->diffForHumans(),
            ]);

        $recent = (clone $base)
            ->orderByDesc('last_seen_at')
            ->limit(6)
            ->get()
            ->map(fn (Router $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'host' => $r->host,
                'status' => $r->status->value,
                'status_label' => $r->status->label(),
                'status_color' => $r->status->color(),
                'routeros_version' => $r->routeros_version,
                'last_seen_at' => $r->last_seen_at?->diffForHumans(),
            ]);

        // 2. Widget filtering:
        // Priority: query param 'widgets' > cookie 'mikweb_dashboard_widgets' > default full set.
        $rawWidgets = $request->query('widgets') ?? $request->cookie('mikweb_dashboard_widgets');
        if ($rawWidgets !== null) {
            $requestedWidgets = array_values(array_filter(
                array_map('trim', is_array($rawWidgets) ? $rawWidgets : explode(',', (string) $rawWidgets))
            ));
        } else {
            $requestedWidgets = ['fleet', 'traffic', 'ppp', 'hotspot', 'dhcp', 'system'];
        }

        // 3. Select target router for live MikroTik widgets
        $selectedRouterId = $request->query('router_id');
        $selectedRouter = null;

        if ($selectedRouterId !== null) {
            $selectedRouter = (clone $base)->where('id', $selectedRouterId)->first();
        }

        if (! $selectedRouter && $routers->isNotEmpty()) {
            $firstOnline = $routers->firstWhere('status', RouterStatus::Online->value);
            $targetId = $firstOnline['id'] ?? $routers->first()['id'];
            $selectedRouter = (clone $base)->where('id', $targetId)->first();
        }

        $liveStats = [
            'device_error' => null,
            'ppp' => null,
            'hotspot' => null,
            'dhcp' => null,
            'system' => null,
            'queues' => null,
        ];
        $interfaces = [];

        // 4. Query ONLY requested MikroTik modules for the selected router
        if ($selectedRouter !== null) {
            $uid = $request->user()->id;
            $access = app(RouterAccess::class);

            // Traffic widget / interface selector
            if (in_array('traffic', $requestedWidgets, true) && $access->allows($request->user(), Permissions::INTERFACE_VIEW, $selectedRouter)) {
                try {
                    $rawInterfaces = $client->list($selectedRouter, '/interface', userId: $uid);
                    $interfaces = array_map(fn ($i) => [
                        'name' => (string) ($i['name'] ?? ''),
                        'type' => (string) ($i['type'] ?? ''),
                        'running' => ($i['running'] ?? 'false') === 'true',
                        'disabled' => ($i['disabled'] ?? 'false') === 'true',
                        'comment' => $i['comment'] ?? null,
                    ], $rawInterfaces);
                } catch (RouterOsException $e) {
                    $liveStats['device_error'] = $e->getMessage();
                }
            }

            // PPP Module (ONLY queried if 'ppp' is requested)
            if (in_array('ppp', $requestedWidgets, true) && $access->allows($request->user(), Permissions::PPP_VIEW, $selectedRouter)) {
                try {
                    $active = $client->list($selectedRouter, '/ppp/active', userId: $uid);
                    $secrets = $client->list($selectedRouter, '/ppp/secret', userId: $uid);
                    $profiles = $client->list($selectedRouter, '/ppp/profile', userId: $uid);

                    $activeCount = count($active);
                    $secretCount = count($secrets);
                    $disabledSecrets = count(array_filter($secrets, fn ($s) => ($s['disabled'] ?? 'false') === 'true'));

                    $liveStats['ppp'] = [
                        'active' => $activeCount,
                        'secrets' => $secretCount,
                        'disabled_secrets' => $disabledSecrets,
                        'profiles' => count($profiles),
                    ];
                } catch (RouterOsException $e) {
                    $liveStats['ppp'] = ['error' => $e->getMessage(), 'active' => 0, 'secrets' => 0, 'disabled_secrets' => 0, 'profiles' => 0];
                }
            }

            // Hotspot Module (ONLY queried if 'hotspot' is requested)
            if (in_array('hotspot', $requestedWidgets, true) && $access->allows($request->user(), Permissions::HOTSPOT_VIEW, $selectedRouter)) {
                try {
                    $active = $client->list($selectedRouter, '/ip/hotspot/active', userId: $uid);
                    $users = $client->list($selectedRouter, '/ip/hotspot/user', userId: $uid);
                    $hosts = $client->list($selectedRouter, '/ip/hotspot/host', userId: $uid);
                    $disabledUsers = count(array_filter($users, fn ($u) => ($u['disabled'] ?? 'false') === 'true'));

                    $liveStats['hotspot'] = [
                        'active' => count($active),
                        'users' => count($users),
                        'disabled_users' => $disabledUsers,
                        'hosts' => count($hosts),
                    ];
                } catch (RouterOsException $e) {
                    $liveStats['hotspot'] = ['error' => $e->getMessage(), 'active' => 0, 'users' => 0, 'disabled_users' => 0, 'hosts' => 0];
                }
            }

            // DHCP Module (ONLY queried if 'dhcp' is requested)
            if (in_array('dhcp', $requestedWidgets, true) && $access->allows($request->user(), Permissions::IP_VIEW, $selectedRouter)) {
                try {
                    $leases = $client->list($selectedRouter, '/ip/dhcp-server/lease', userId: $uid);
                    $bound = count(array_filter($leases, fn ($l) => ($l['status'] ?? '') === 'bound'));
                    $static = count(array_filter($leases, fn ($l) => ($l['dynamic'] ?? 'false') === 'false'));

                    $liveStats['dhcp'] = [
                        'total' => count($leases),
                        'bound' => $bound,
                        'static' => $static,
                    ];
                } catch (RouterOsException $e) {
                    $liveStats['dhcp'] = ['error' => $e->getMessage(), 'total' => 0, 'bound' => 0, 'static' => 0];
                }
            }

            // System Module (ONLY queried if 'system' is requested)
            if (in_array('system', $requestedWidgets, true) && $access->allows($request->user(), Permissions::SYSTEM_VIEW, $selectedRouter)) {
                try {
                    $res = $client->list($selectedRouter, '/system/resource', userId: $uid)[0] ?? [];
                    $totalMem = (int) ($res['total-memory'] ?? 0);
                    $freeMem = (int) ($res['free-memory'] ?? 0);
                    $usedMemPct = $totalMem > 0 ? (int) round((($totalMem - $freeMem) / $totalMem) * 100) : 0;

                    $totalHdd = (int) ($res['total-hdd-space'] ?? 0);
                    $freeHdd = (int) ($res['free-hdd-space'] ?? 0);
                    $usedHddPct = $totalHdd > 0 ? (int) round((($totalHdd - $freeHdd) / $totalHdd) * 100) : 0;

                    $liveStats['system'] = [
                        'cpu_load' => (int) ($res['cpu-load'] ?? 0),
                        'cpu_count' => (int) ($res['cpu-count'] ?? 1),
                        'uptime' => (string) ($res['uptime'] ?? '—'),
                        'version' => (string) ($res['version'] ?? '—'),
                        'board_name' => (string) ($res['board-name'] ?? ($res['platform'] ?? '—')),
                        'memory_used_pct' => $usedMemPct,
                        'memory_free_human' => round($freeMem / (1024 * 1024), 1).' MB',
                        'memory_total_human' => round($totalMem / (1024 * 1024), 1).' MB',
                        'hdd_used_pct' => $usedHddPct,
                    ];
                } catch (RouterOsException $e) {
                    $liveStats['system'] = ['error' => $e->getMessage()];
                }
            }

            // Queues Module (ONLY queried if 'queues' is requested)
            if (in_array('queues', $requestedWidgets, true) && $access->allows($request->user(), Permissions::QUEUE_VIEW, $selectedRouter)) {
                try {
                    $queues = $client->list($selectedRouter, '/queue/simple', userId: $uid);
                    $liveStats['queues'] = [
                        'total' => count($queues),
                        'active' => count(array_filter($queues, fn ($q) => ($q['disabled'] ?? 'false') === 'false')),
                    ];
                } catch (RouterOsException $e) {
                    $liveStats['queues'] = ['error' => $e->getMessage(), 'total' => 0, 'active' => 0];
                }
            }
        }

        $payload = [
            'counts' => $counts,
            'recentRouters' => $recent,
            'routers' => $routers,
            'selectedRouterId' => $selectedRouter?->id,
            'selectedRouterName' => $selectedRouter?->name,
            'enabledWidgets' => $requestedWidgets,
            'liveStats' => $liveStats,
            'interfaces' => $interfaces,
        ];

        if ($request->wantsJson()) {
            return response()->json($payload);
        }

        return Inertia::render('Dashboard', $payload);
    }
}

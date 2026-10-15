<?php

declare(strict_types=1);

namespace App\Http\Controllers\Router\Modules;

use App\Domain\Audit\AuditLogger;
use App\Domain\Router\Data\RouterPresenter;
use App\Domain\Router\Models\Router;
use App\Http\Controllers\Controller;
use App\Services\RouterOS\ResourceClient;
use App\Support\Access\Permissions;
use App\Support\Access\RouterAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InterfaceController extends Controller
{
    use InteractsWithRouterModule;

    private const MENU = '/interface';

    public function index(Request $request, Router $router, ResourceClient $client): Response|JsonResponse
    {
        $this->ensureModuleAccess($router, Permissions::INTERFACE_VIEW);
        $router->load('capability');

        $read = $this->deviceRead(fn () => $client->list($router, self::MENU, userId: $request->user()->id));

        if ($request->wantsJson()) {
            return response()->json([
                'interfaces' => $read['data'],
                'deviceError' => $read['error'],
            ]);
        }

        return Inertia::render('Routers/Modules/Interfaces', [
            'router' => RouterPresenter::header($router),
            'interfaces' => $read['data'],
            'deviceError' => $read['error'],
            'can' => ['manage' => app(RouterAccess::class)->allows($request->user(), Permissions::INTERFACE_MANAGE, $router)],
        ]);
    }

    public function enable(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::INTERFACE_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->enable($router, self::MENU, $id, $request->user()->id);
            AuditLogger::record('interface.enable', $router, new: ['id' => $id], routerId: $router->id);
        }, 'Interface enabled.');
    }

    public function disable(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::INTERFACE_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->disable($router, self::MENU, $id, $request->user()->id);
            AuditLogger::record('interface.disable', $router, new: ['id' => $id], routerId: $router->id);
        }, 'Interface disabled.');
    }

    public function resetCounters(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::INTERFACE_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->action($router, self::MENU.'/reset-counters', ['.id' => $id], $request->user()->id);
        }, 'Counters reset.');
    }

    public function batch(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::INTERFACE_MANAGE);
        $data = $request->validate([
            'action' => ['required', 'string', 'in:enable,disable,reset_counters'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'string'],
        ]);

        $action = $data['action'];
        $ids = $data['ids'];
        $uid = $request->user()->id;

        return $this->deviceAction(function () use ($client, $router, $action, $ids, $uid) {
            foreach ($ids as $id) {
                if ($action === 'enable') {
                    $client->enable($router, self::MENU, $id, $uid);
                } elseif ($action === 'disable') {
                    $client->disable($router, self::MENU, $id, $uid);
                } elseif ($action === 'reset_counters') {
                    $client->action($router, self::MENU.'/reset-counters', ['.id' => $id], $uid);
                }
            }

            AuditLogger::record("interface.batch_{$action}", $router, new: [
                'count' => count($ids),
                'action' => $action,
            ], routerId: $router->id);
        }, match ($action) {
            'enable' => count($ids).' interface berhasil diaktifkan.',
            'disable' => count($ids).' interface berhasil dinonaktifkan.',
            'reset_counters' => count($ids).' counter interface berhasil di-reset.',
        });
    }

    public function traffic(Request $request, Router $router, ResourceClient $client): JsonResponse
    {
        $this->ensureModuleAccess($router, Permissions::INTERFACE_VIEW);
        $interface = (string) $request->input('interface', 'ether1');

        try {
            $rows = $client->action($router, '/interface/monitor-traffic', [
                'interface' => $interface,
                'once' => 'true',
            ], $request->user()->id);

            $data = $rows[0] ?? [];
            $rxBps = (int) ($data['rx-bits-per-second'] ?? 0);
            $txBps = (int) ($data['tx-bits-per-second'] ?? 0);

            return response()->json([
                'success' => true,
                'interface' => $interface,
                'rx_bps' => $rxBps,
                'tx_bps' => $txBps,
                'rx_packets' => (int) ($data['rx-packets-per-second'] ?? 0),
                'tx_packets' => (int) ($data['tx-packets-per-second'] ?? 0),
                'timestamp' => now()->timestamp,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'interface' => $interface,
                'rx_bps' => 0,
                'tx_bps' => 0,
                'error' => $e->getMessage(),
                'timestamp' => now()->timestamp,
            ]);
        }
    }
}

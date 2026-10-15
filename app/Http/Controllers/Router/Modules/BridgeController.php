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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bridge management: Bridge interfaces and Bridge Member Ports.
 */
class BridgeController extends Controller
{
    use InteractsWithRouterModule;

    private const BRIDGE = '/interface/bridge';

    private const PORT = '/interface/bridge/port';

    public function index(Request $request, Router $router, ResourceClient $client): Response
    {
        $this->ensureModuleAccess($router, Permissions::INTERFACE_VIEW);
        $router->load('capability');
        $uid = $request->user()->id;

        $bridges = $this->deviceRead(fn () => $client->list($router, self::BRIDGE, userId: $uid));
        $ports = $this->deviceRead(fn () => $client->list($router, self::PORT, userId: $uid));
        $interfaces = $this->deviceRead(fn () => array_map(fn ($i) => $i['name'] ?? '', $client->list($router, '/interface', userId: $uid)));

        return Inertia::render('Routers/Modules/Bridge', [
            'router' => RouterPresenter::header($router),
            'bridges' => $bridges['data'],
            'ports' => $ports['data'],
            'interfaces' => $interfaces['data'],
            'deviceError' => $bridges['error'] ?? $ports['error'],
            'can' => ['manage' => app(RouterAccess::class)->allows($request->user(), Permissions::INTERFACE_MANAGE, $router)],
        ]);
    }

    public function storeBridge(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::INTERFACE_MANAGE);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'arp' => ['nullable', 'string', 'max:32'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = ['name' => $data['name']];
        if (filled($data['arp'] ?? null)) {
            $payload['arp'] = $data['arp'];
        }
        if (filled($data['comment'] ?? null)) {
            $payload['comment'] = $data['comment'];
        }

        return $this->deviceAction(function () use ($client, $router, $payload, $request) {
            $client->add($router, self::BRIDGE, $payload, $request->user()->id);
            AuditLogger::record('bridge.add', $router, new: ['name' => $payload['name']], routerId: $router->id);
        }, 'Bridge created.');
    }

    public function toggleBridge(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::INTERFACE_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $disable = $request->boolean('disable');

        return $this->deviceAction(function () use ($client, $router, $id, $disable, $request) {
            $disable
                ? $client->disable($router, self::BRIDGE, $id, $request->user()->id)
                : $client->enable($router, self::BRIDGE, $id, $request->user()->id);
            AuditLogger::record('bridge.toggle', $router, new: ['id' => $id, 'disabled' => $disable], routerId: $router->id);
        }, $disable ? 'Bridge disabled.' : 'Bridge enabled.');
    }

    public function destroyBridge(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::INTERFACE_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, self::BRIDGE, $id, $request->user()->id);
            AuditLogger::record('bridge.remove', $router, old: ['id' => $id], routerId: $router->id);
        }, 'Bridge removed.');
    }

    public function storePort(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::INTERFACE_MANAGE);
        $data = $request->validate([
            'interface' => ['required', 'string', 'max:64'],
            'bridge' => ['required', 'string', 'max:64'],
            'priority' => ['nullable', 'string', 'max:16'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = ['interface' => $data['interface'], 'bridge' => $data['bridge']];
        if (filled($data['priority'] ?? null)) {
            $payload['priority'] = $data['priority'];
        }
        if (filled($data['comment'] ?? null)) {
            $payload['comment'] = $data['comment'];
        }

        return $this->deviceAction(function () use ($client, $router, $payload, $request) {
            $client->add($router, self::PORT, $payload, $request->user()->id);
            AuditLogger::record('bridge.port_add', $router, new: ['interface' => $payload['interface'], 'bridge' => $payload['bridge']], routerId: $router->id);
        }, 'Bridge port added.');
    }

    public function togglePort(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::INTERFACE_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $disable = $request->boolean('disable');

        return $this->deviceAction(function () use ($client, $router, $id, $disable, $request) {
            $disable
                ? $client->disable($router, self::PORT, $id, $request->user()->id)
                : $client->enable($router, self::PORT, $id, $request->user()->id);
            AuditLogger::record('bridge.port_toggle', $router, new: ['id' => $id, 'disabled' => $disable], routerId: $router->id);
        }, $disable ? 'Bridge port disabled.' : 'Bridge port enabled.');
    }

    public function destroyPort(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::INTERFACE_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, self::PORT, $id, $request->user()->id);
            AuditLogger::record('bridge.port_remove', $router, old: ['id' => $id], routerId: $router->id);
        }, 'Bridge port removed.');
    }

    public function batchBridges(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::INTERFACE_MANAGE);
        $data = $request->validate([
            'action' => ['required', 'string', 'in:enable,disable,destroy'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'string'],
        ]);

        $action = $data['action'];
        $ids = $data['ids'];
        $uid = $request->user()->id;

        return $this->deviceAction(function () use ($client, $router, $action, $ids, $uid) {
            foreach ($ids as $id) {
                if ($action === 'enable') {
                    $client->enable($router, self::BRIDGE, $id, $uid);
                } elseif ($action === 'disable') {
                    $client->disable($router, self::BRIDGE, $id, $uid);
                } elseif ($action === 'destroy') {
                    $client->remove($router, self::BRIDGE, $id, $uid);
                }
            }

            AuditLogger::record("bridge.batch_{$action}", $router, new: [
                'count' => count($ids),
                'action' => $action,
            ], routerId: $router->id);
        }, match ($action) {
            'enable' => count($ids).' bridge berhasil diaktifkan.',
            'disable' => count($ids).' bridge berhasil dinonaktifkan.',
            'destroy' => count($ids).' bridge berhasil dihapus.',
        });
    }

    public function batchPorts(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::INTERFACE_MANAGE);
        $data = $request->validate([
            'action' => ['required', 'string', 'in:enable,disable,destroy'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'string'],
        ]);

        $action = $data['action'];
        $ids = $data['ids'];
        $uid = $request->user()->id;

        return $this->deviceAction(function () use ($client, $router, $action, $ids, $uid) {
            foreach ($ids as $id) {
                if ($action === 'enable') {
                    $client->enable($router, self::PORT, $id, $uid);
                } elseif ($action === 'disable') {
                    $client->disable($router, self::PORT, $id, $uid);
                } elseif ($action === 'destroy') {
                    $client->remove($router, self::PORT, $id, $uid);
                }
            }

            AuditLogger::record("bridge.port_batch_{$action}", $router, new: [
                'count' => count($ids),
                'action' => $action,
            ], routerId: $router->id);
        }, match ($action) {
            'enable' => count($ids).' port bridge berhasil diaktifkan.',
            'disable' => count($ids).' port bridge berhasil dinonaktifkan.',
            'destroy' => count($ids).' port bridge berhasil dihapus.',
        });
    }
}

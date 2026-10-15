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
 * RouterOS WireGuard VPN management (/interface/wireguard and /interface/wireguard/peers).
 */
class WireguardController extends Controller
{
    use InteractsWithRouterModule;

    private const WG_MENU = '/interface/wireguard';

    private const PEER_MENU = '/interface/wireguard/peers';

    public function index(Request $request, Router $router, ResourceClient $client): Response
    {
        $this->ensureModuleAccess($router, Permissions::INTERFACE_VIEW);
        $router->load('capability');
        $uid = $request->user()->id;

        $interfaces = $this->deviceRead(fn () => $client->list($router, self::WG_MENU, userId: $uid));
        $peers = $this->deviceRead(fn () => $client->list($router, self::PEER_MENU, userId: $uid));

        return Inertia::render('Routers/Modules/Wireguard', [
            'router' => RouterPresenter::header($router),
            'interfaces' => $interfaces['data'],
            'peers' => $peers['data'],
            'deviceError' => $interfaces['error'] ?? $peers['error'],
            'can' => ['manage' => app(RouterAccess::class)->allows($request->user(), Permissions::INTERFACE_MANAGE, $router)],
        ]);
    }

    public function storeInterface(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::INTERFACE_MANAGE);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'listen_port' => ['nullable', 'integer', 'between:1,65535'],
            'mtu' => ['nullable', 'integer', 'between:1280,1500'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = ['name' => $data['name']];
        if (isset($data['listen_port'])) {
            $payload['listen-port'] = (string) $data['listen_port'];
        }
        if (isset($data['mtu'])) {
            $payload['mtu'] = (string) $data['mtu'];
        }
        if (filled($data['comment'] ?? null)) {
            $payload['comment'] = $data['comment'];
        }

        return $this->deviceAction(function () use ($client, $router, $payload, $request, $data) {
            $client->add($router, self::WG_MENU, $payload, $request->user()->id);
            AuditLogger::record('wireguard.interface_add', $router, new: ['name' => $data['name']], routerId: $router->id);
        }, "WireGuard interface '{$data['name']}' created.");
    }

    public function updateInterface(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::INTERFACE_MANAGE);
        $data = $request->validate([
            'id' => ['required', 'string'],
            'name' => ['required', 'string', 'max:64'],
            'listen_port' => ['nullable', 'integer', 'between:1,65535'],
            'mtu' => ['nullable', 'integer', 'between:1280,1500'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = ['name' => $data['name']];
        if (isset($data['listen_port'])) {
            $payload['listen-port'] = (string) $data['listen_port'];
        }
        if (isset($data['mtu'])) {
            $payload['mtu'] = (string) $data['mtu'];
        }
        if (isset($data['comment'])) {
            $payload['comment'] = (string) $data['comment'];
        }

        return $this->deviceAction(function () use ($client, $router, $data, $payload, $request) {
            $client->set($router, self::WG_MENU, $data['id'], $payload, $request->user()->id);
            AuditLogger::record('wireguard.interface_update', $router, new: ['id' => $data['id']], routerId: $router->id);
        }, 'WireGuard interface updated.');
    }

    public function toggleInterface(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::INTERFACE_MANAGE);
        $data = $request->validate([
            'id' => ['required', 'string'],
            'disable' => ['required', 'boolean'],
        ]);

        $verb = $data['disable'] ? 'disable' : 'enable';

        return $this->deviceAction(function () use ($client, $router, $data, $verb, $request) {
            $data['disable']
                ? $client->disable($router, self::WG_MENU, $data['id'], $request->user()->id)
                : $client->enable($router, self::WG_MENU, $data['id'], $request->user()->id);
            AuditLogger::record("wireguard.interface_{$verb}", $router, new: ['id' => $data['id']], routerId: $router->id);
        }, "WireGuard interface {$verb}d.");
    }

    public function destroyInterface(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::INTERFACE_MANAGE);
        $data = $request->validate([
            'id' => ['required', 'string'],
        ]);

        return $this->deviceAction(function () use ($client, $router, $data, $request) {
            $client->remove($router, self::WG_MENU, $data['id'], $request->user()->id);
            AuditLogger::record('wireguard.interface_delete', $router, new: ['id' => $data['id']], routerId: $router->id);
        }, 'WireGuard interface removed.');
    }

    public function storePeer(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::INTERFACE_MANAGE);
        $data = $request->validate([
            'interface' => ['required', 'string', 'max:64'],
            'public_key' => ['required', 'string', 'max:128'],
            'allowed_address' => ['required', 'string', 'max:255'],
            'endpoint_address' => ['nullable', 'string', 'max:255'],
            'endpoint_port' => ['nullable', 'integer', 'between:1,65535'],
            'persistent_keepalive' => ['nullable', 'string', 'max:32'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = [
            'interface' => $data['interface'],
            'public-key' => $data['public_key'],
            'allowed-address' => $data['allowed_address'],
        ];

        if (filled($data['endpoint_address'] ?? null)) {
            $payload['endpoint-address'] = $data['endpoint_address'];
        }
        if (isset($data['endpoint_port'])) {
            $payload['endpoint-port'] = (string) $data['endpoint_port'];
        }
        if (filled($data['persistent_keepalive'] ?? null)) {
            $payload['persistent-keepalive'] = $data['persistent_keepalive'];
        }
        if (filled($data['comment'] ?? null)) {
            $payload['comment'] = $data['comment'];
        }

        return $this->deviceAction(function () use ($client, $router, $payload, $request, $data) {
            $client->add($router, self::PEER_MENU, $payload, $request->user()->id);
            AuditLogger::record('wireguard.peer_add', $router, new: ['interface' => $data['interface']], routerId: $router->id);
        }, 'WireGuard peer added.');
    }

    public function updatePeer(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::INTERFACE_MANAGE);
        $data = $request->validate([
            'id' => ['required', 'string'],
            'interface' => ['required', 'string', 'max:64'],
            'public_key' => ['required', 'string', 'max:128'],
            'allowed_address' => ['required', 'string', 'max:255'],
            'endpoint_address' => ['nullable', 'string', 'max:255'],
            'endpoint_port' => ['nullable', 'integer', 'between:1,65535'],
            'persistent_keepalive' => ['nullable', 'string', 'max:32'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = [
            'interface' => $data['interface'],
            'public-key' => $data['public_key'],
            'allowed-address' => $data['allowed_address'],
        ];

        if (isset($data['endpoint_address'])) {
            $payload['endpoint-address'] = (string) $data['endpoint_address'];
        }
        if (isset($data['endpoint_port'])) {
            $payload['endpoint-port'] = (string) $data['endpoint_port'];
        }
        if (isset($data['persistent_keepalive'])) {
            $payload['persistent-keepalive'] = (string) $data['persistent_keepalive'];
        }
        if (isset($data['comment'])) {
            $payload['comment'] = (string) $data['comment'];
        }

        return $this->deviceAction(function () use ($client, $router, $data, $payload, $request) {
            $client->set($router, self::PEER_MENU, $data['id'], $payload, $request->user()->id);
            AuditLogger::record('wireguard.peer_update', $router, new: ['id' => $data['id']], routerId: $router->id);
        }, 'WireGuard peer updated.');
    }

    public function togglePeer(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::INTERFACE_MANAGE);
        $data = $request->validate([
            'id' => ['required', 'string'],
            'disable' => ['required', 'boolean'],
        ]);

        $verb = $data['disable'] ? 'disable' : 'enable';

        return $this->deviceAction(function () use ($client, $router, $data, $verb, $request) {
            $data['disable']
                ? $client->disable($router, self::PEER_MENU, $data['id'], $request->user()->id)
                : $client->enable($router, self::PEER_MENU, $data['id'], $request->user()->id);
            AuditLogger::record("wireguard.peer_{$verb}", $router, new: ['id' => $data['id']], routerId: $router->id);
        }, "WireGuard peer {$verb}d.");
    }

    public function destroyPeer(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::INTERFACE_MANAGE);
        $data = $request->validate([
            'id' => ['required', 'string'],
        ]);

        return $this->deviceAction(function () use ($client, $router, $data, $request) {
            $client->remove($router, self::PEER_MENU, $data['id'], $request->user()->id);
            AuditLogger::record('wireguard.peer_delete', $router, new: ['id' => $data['id']], routerId: $router->id);
        }, 'WireGuard peer removed.');
    }

    public function batchPeers(Request $request, Router $router, ResourceClient $client): RedirectResponse
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
                    $client->enable($router, self::PEER_MENU, $id, $uid);
                } elseif ($action === 'disable') {
                    $client->disable($router, self::PEER_MENU, $id, $uid);
                } elseif ($action === 'destroy') {
                    $client->remove($router, self::PEER_MENU, $id, $uid);
                }
            }

            AuditLogger::record("wireguard.peer_batch_{$action}", $router, new: [
                'count' => count($ids),
                'action' => $action,
            ], routerId: $router->id);
        }, match ($action) {
            'enable' => count($ids).' WireGuard peer berhasil diaktifkan.',
            'disable' => count($ids).' WireGuard peer berhasil dinonaktifkan.',
            'destroy' => count($ids).' WireGuard peer berhasil dihapus.',
        });
    }
}

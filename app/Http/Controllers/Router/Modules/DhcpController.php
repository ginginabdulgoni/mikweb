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
 * DHCP Server, Leases, Networks, and IP Pools.
 * Full Winbox functionality including "Make Static" for dynamic client leases.
 */
class DhcpController extends Controller
{
    use InteractsWithRouterModule;

    private const SERVER = '/ip/dhcp-server';

    private const LEASE = '/ip/dhcp-server/lease';

    private const NETWORK = '/ip/dhcp-server/network';

    private const POOL = '/ip/pool';

    public function index(Request $request, Router $router, ResourceClient $client): Response
    {
        $this->ensureModuleAccess($router, Permissions::IP_VIEW);
        $router->load('capability');
        $uid = $request->user()->id;

        $servers = $this->deviceRead(fn () => $client->list($router, self::SERVER, userId: $uid));
        $leases = $this->deviceRead(fn () => $client->list($router, self::LEASE, userId: $uid));
        $networks = $this->deviceRead(fn () => $client->list($router, self::NETWORK, userId: $uid));
        $pools = $this->deviceRead(fn () => $client->list($router, self::POOL, userId: $uid));
        $interfaces = $this->deviceRead(fn () => array_map(fn ($i) => $i['name'] ?? '', $client->list($router, '/interface', userId: $uid)));

        $poolMap = [];
        foreach ($pools['data'] as $p) {
            if (isset($p['.id'], $p['name'])) {
                $poolMap[$p['.id']] = (string) $p['name'];
            }
        }

        $resolvePool = static function (?string $value) use ($poolMap): ?string {
            if ($value === null || $value === '') {
                return $value;
            }

            return $poolMap[$value] ?? $value;
        };

        $resolvedServers = array_map(function (array $s) use ($resolvePool): array {
            if (isset($s['address-pool'])) {
                $s['address-pool'] = $resolvePool($s['address-pool']);
            }

            return $s;
        }, $servers['data']);

        return Inertia::render('Routers/Modules/Dhcp', [
            'router' => RouterPresenter::header($router),
            'servers' => $resolvedServers,
            'leases' => $leases['data'],
            'networks' => $networks['data'],
            'pools' => $pools['data'],
            'interfaces' => $interfaces['data'],
            'deviceError' => $servers['error'] ?? $leases['error'] ?? $networks['error'] ?? $pools['error'],
            'can' => ['manage' => app(RouterAccess::class)->allows($request->user(), Permissions::IP_MANAGE, $router)],
        ]);
    }

    // ── Leases ──────────────────────────────────────────────────────────

    public function storeLease(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $data = $request->validate([
            'address' => ['required', 'string', 'max:64'],
            'mac_address' => ['required', 'string', 'max:32'],
            'server' => ['nullable', 'string', 'max:64'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = [
            'address' => $data['address'],
            'mac-address' => $data['mac_address'],
        ];
        if (filled($data['server'] ?? null)) {
            $payload['server'] = $data['server'];
        }
        if (filled($data['comment'] ?? null)) {
            $payload['comment'] = $data['comment'];
        }

        return $this->deviceAction(function () use ($client, $router, $payload, $request) {
            $client->add($router, self::LEASE, $payload, $request->user()->id);
            AuditLogger::record('dhcp.lease_add', $router, new: ['address' => $payload['address'], 'mac' => $payload['mac-address']], routerId: $router->id);
        }, 'Static DHCP lease added.');
    }

    public function updateLease(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $data = $request->validate([
            'address' => ['required', 'string', 'max:64'],
            'mac_address' => ['required', 'string', 'max:32'],
            'server' => ['nullable', 'string', 'max:64'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = [
            'address' => $data['address'],
            'mac-address' => $data['mac_address'],
            'server' => (string) ($data['server'] ?? 'all'),
            'comment' => (string) ($data['comment'] ?? ''),
        ];

        return $this->deviceAction(function () use ($client, $router, $id, $payload, $request) {
            $client->set($router, self::LEASE, $id, $payload, $request->user()->id);
            AuditLogger::record('dhcp.lease_update', $router, new: ['id' => $id], routerId: $router->id);
        }, 'DHCP lease updated.');
    }

    public function makeStaticLease(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->action($router, self::LEASE.'/make-static', ['.id' => $id], $request->user()->id);
            AuditLogger::record('dhcp.lease_make_static', $router, new: ['id' => $id], routerId: $router->id);
        }, 'DHCP lease is now static.');
    }

    public function toggleLease(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $disable = $request->boolean('disable');

        return $this->deviceAction(function () use ($client, $router, $id, $disable, $request) {
            $disable
                ? $client->disable($router, self::LEASE, $id, $request->user()->id)
                : $client->enable($router, self::LEASE, $id, $request->user()->id);
            AuditLogger::record('dhcp.lease_toggle', $router, new: ['id' => $id, 'disabled' => $disable], routerId: $router->id);
        }, $disable ? 'Lease disabled.' : 'Lease enabled.');
    }

    public function destroyLease(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, self::LEASE, $id, $request->user()->id);
            AuditLogger::record('dhcp.lease_remove', $router, old: ['id' => $id], routerId: $router->id);
        }, 'DHCP lease removed.');
    }

    public function batchLeases(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $data = $request->validate([
            'action' => ['required', 'string', 'in:make_static,enable,disable,destroy'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'string'],
        ]);

        $action = $data['action'];
        $ids = $data['ids'];
        $uid = $request->user()->id;

        return $this->deviceAction(function () use ($client, $router, $action, $ids, $uid) {
            foreach ($ids as $id) {
                if ($action === 'make_static') {
                    $client->action($router, self::LEASE.'/make-static', ['.id' => $id], $uid);
                } elseif ($action === 'enable') {
                    $client->enable($router, self::LEASE, $id, $uid);
                } elseif ($action === 'disable') {
                    $client->disable($router, self::LEASE, $id, $uid);
                } elseif ($action === 'destroy') {
                    $client->remove($router, self::LEASE, $id, $uid);
                }
            }

            AuditLogger::record("dhcp.lease_batch_{$action}", $router, new: [
                'count' => count($ids),
                'action' => $action,
            ], routerId: $router->id);
        }, match ($action) {
            'make_static' => count($ids).' DHCP lease berhasil dijadikan static.',
            'enable' => count($ids).' DHCP lease berhasil diaktifkan.',
            'disable' => count($ids).' DHCP lease berhasil dinonaktifkan.',
            'destroy' => count($ids).' DHCP lease berhasil dihapus.',
        });
    }

    // ── Servers ─────────────────────────────────────────────────────────

    public function storeServer(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'interface' => ['required', 'string', 'max:64'],
            'address_pool' => ['nullable', 'string', 'max:64'],
            'lease_time' => ['nullable', 'string', 'max:32'],
        ]);

        $payload = [
            'name' => $data['name'],
            'interface' => $data['interface'],
            'lease-time' => (string) ($data['lease_time'] ?: '12h'),
        ];
        if (filled($data['address_pool'] ?? null)) {
            $payload['address-pool'] = $data['address_pool'];
        }

        return $this->deviceAction(function () use ($client, $router, $payload, $request) {
            $client->add($router, self::SERVER, $payload, $request->user()->id);
            AuditLogger::record('dhcp.server_add', $router, new: ['name' => $payload['name']], routerId: $router->id);
        }, 'DHCP server created.');
    }

    public function toggleServer(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $disable = $request->boolean('disable');

        return $this->deviceAction(function () use ($client, $router, $id, $disable, $request) {
            $disable
                ? $client->disable($router, self::SERVER, $id, $request->user()->id)
                : $client->enable($router, self::SERVER, $id, $request->user()->id);
            AuditLogger::record('dhcp.server_toggle', $router, new: ['id' => $id, 'disabled' => $disable], routerId: $router->id);
        }, $disable ? 'DHCP server disabled.' : 'DHCP server enabled.');
    }

    public function destroyServer(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, self::SERVER, $id, $request->user()->id);
            AuditLogger::record('dhcp.server_remove', $router, old: ['id' => $id], routerId: $router->id);
        }, 'DHCP server removed.');
    }

    // ── Networks ────────────────────────────────────────────────────────

    public function storeNetwork(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $data = $request->validate([
            'address' => ['required', 'string', 'max:64'],
            'gateway' => ['nullable', 'string', 'max:64'],
            'dns_server' => ['nullable', 'string', 'max:128'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = ['address' => $data['address']];
        if (filled($data['gateway'] ?? null)) {
            $payload['gateway'] = $data['gateway'];
        }
        if (filled($data['dns_server'] ?? null)) {
            $payload['dns-server'] = $data['dns_server'];
        }
        if (filled($data['comment'] ?? null)) {
            $payload['comment'] = $data['comment'];
        }

        return $this->deviceAction(function () use ($client, $router, $payload, $request) {
            $client->add($router, self::NETWORK, $payload, $request->user()->id);
            AuditLogger::record('dhcp.network_add', $router, new: ['address' => $payload['address']], routerId: $router->id);
        }, 'DHCP network created.');
    }

    public function destroyNetwork(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, self::NETWORK, $id, $request->user()->id);
            AuditLogger::record('dhcp.network_remove', $router, old: ['id' => $id], routerId: $router->id);
        }, 'DHCP network removed.');
    }

    // ── Pools ───────────────────────────────────────────────────────────

    public function storePool(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'ranges' => ['required', 'string', 'max:128'],
            'next_pool' => ['nullable', 'string', 'max:64'],
        ]);

        $payload = ['name' => $data['name'], 'ranges' => $data['ranges']];
        if (filled($data['next_pool'] ?? null)) {
            $payload['next-pool'] = $data['next_pool'];
        }

        return $this->deviceAction(function () use ($client, $router, $payload, $request) {
            $client->add($router, self::POOL, $payload, $request->user()->id);
            AuditLogger::record('ip.pool_add', $router, new: ['name' => $payload['name'], 'ranges' => $payload['ranges']], routerId: $router->id);
        }, 'IP Pool created.');
    }

    public function destroyPool(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, self::POOL, $id, $request->user()->id);
            AuditLogger::record('ip.pool_remove', $router, old: ['id' => $id], routerId: $router->id);
        }, 'IP Pool removed.');
    }
}

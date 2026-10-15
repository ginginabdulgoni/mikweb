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
 * IP DHCP Client management (/ip/dhcp-client).
 * Configures dynamic WAN / interface IP address acquisition from upstream DHCP servers.
 */
class DhcpClientController extends Controller
{
    use InteractsWithRouterModule;

    private const MENU = '/ip/dhcp-client';

    public function index(Request $request, Router $router, ResourceClient $client): Response
    {
        $this->ensureModuleAccess($router, Permissions::IP_VIEW);
        $router->load('capability');
        $uid = $request->user()->id;

        $clients = $this->deviceRead(fn () => $client->list($router, self::MENU, userId: $uid));
        $interfaces = $this->deviceRead(fn () => array_map(fn ($i) => $i['name'] ?? '', $client->list($router, '/interface', userId: $uid)));

        return Inertia::render('Routers/Modules/DhcpClient', [
            'router' => RouterPresenter::header($router),
            'clients' => $clients['data'],
            'interfaces' => $interfaces['data'],
            'deviceError' => $clients['error'],
            'can' => ['manage' => app(RouterAccess::class)->allows($request->user(), Permissions::IP_MANAGE, $router)],
        ]);
    }

    public function store(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $data = $request->validate([
            'interface' => ['required', 'string', 'max:64'],
            'use_peer_dns' => ['nullable', 'string', 'in:yes,no'],
            'use_peer_ntp' => ['nullable', 'string', 'in:yes,no'],
            'add_default_route' => ['nullable', 'string', 'in:yes,no,special-class'],
            'default_route_distance' => ['nullable', 'integer', 'between:1,255'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = [
            'interface' => $data['interface'],
            'use-peer-dns' => $data['use_peer_dns'] ?? 'yes',
            'use-peer-ntp' => $data['use_peer_ntp'] ?? 'yes',
            'add-default-route' => $data['add_default_route'] ?? 'yes',
        ];

        if (isset($data['default_route_distance'])) {
            $payload['default-route-distance'] = (string) $data['default_route_distance'];
        }

        if (filled($data['comment'] ?? null)) {
            $payload['comment'] = $data['comment'];
        }

        return $this->deviceAction(function () use ($client, $router, $payload, $request, $data) {
            $client->add($router, self::MENU, $payload, $request->user()->id);
            AuditLogger::record('ip.dhcp_client_add', $router, new: ['interface' => $data['interface']], routerId: $router->id);
        }, 'DHCP client added successfully.');
    }

    public function update(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $data = $request->validate([
            'id' => ['required', 'string'],
            'interface' => ['required', 'string', 'max:64'],
            'use_peer_dns' => ['nullable', 'string', 'in:yes,no'],
            'use_peer_ntp' => ['nullable', 'string', 'in:yes,no'],
            'add_default_route' => ['nullable', 'string', 'in:yes,no,special-class'],
            'default_route_distance' => ['nullable', 'integer', 'between:1,255'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = [
            'interface' => $data['interface'],
            'use-peer-dns' => $data['use_peer_dns'] ?? 'yes',
            'use-peer-ntp' => $data['use_peer_ntp'] ?? 'yes',
            'add-default-route' => $data['add_default_route'] ?? 'yes',
        ];

        if (isset($data['default_route_distance'])) {
            $payload['default-route-distance'] = (string) $data['default_route_distance'];
        }

        if (isset($data['comment'])) {
            $payload['comment'] = (string) $data['comment'];
        }

        return $this->deviceAction(function () use ($client, $router, $data, $payload, $request) {
            $client->set($router, self::MENU, $data['id'], $payload, $request->user()->id);
            AuditLogger::record('ip.dhcp_client_update', $router, new: ['id' => $data['id'], 'interface' => $data['interface']], routerId: $router->id);
        }, 'DHCP client updated successfully.');
    }

    public function toggle(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $data = $request->validate([
            'id' => ['required', 'string'],
            'disable' => ['required', 'boolean'],
        ]);

        $verb = $data['disable'] ? 'disable' : 'enable';

        return $this->deviceAction(function () use ($client, $router, $data, $verb, $request) {
            $data['disable']
                ? $client->disable($router, self::MENU, $data['id'], $request->user()->id)
                : $client->enable($router, self::MENU, $data['id'], $request->user()->id);
            AuditLogger::record("ip.dhcp_client_{$verb}", $router, new: ['id' => $data['id']], routerId: $router->id);
        }, "DHCP client {$verb}d.");
    }

    public function release(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $data = $request->validate([
            'id' => ['required', 'string'],
        ]);

        return $this->deviceAction(function () use ($client, $router, $data, $request) {
            $client->action($router, self::MENU.'/release', ['.id' => $data['id']], $request->user()->id);
            AuditLogger::record('ip.dhcp_client_release', $router, new: ['id' => $data['id']], routerId: $router->id);
        }, 'DHCP lease released.');
    }

    public function renew(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $data = $request->validate([
            'id' => ['required', 'string'],
        ]);

        return $this->deviceAction(function () use ($client, $router, $data, $request) {
            $client->action($router, self::MENU.'/renew', ['.id' => $data['id']], $request->user()->id);
            AuditLogger::record('ip.dhcp_client_renew', $router, new: ['id' => $data['id']], routerId: $router->id);
        }, 'DHCP lease renewed.');
    }

    public function destroy(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $data = $request->validate([
            'id' => ['required', 'string'],
        ]);

        return $this->deviceAction(function () use ($client, $router, $data, $request) {
            $client->remove($router, self::MENU, $data['id'], $request->user()->id);
            AuditLogger::record('ip.dhcp_client_delete', $router, new: ['id' => $data['id']], routerId: $router->id);
        }, 'DHCP client removed.');
    }

    public function batch(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $data = $request->validate([
            'action' => ['required', 'string', 'in:enable,disable,release,renew,destroy'],
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
                } elseif ($action === 'release') {
                    $client->action($router, self::MENU.'/release', ['.id' => $id], $uid);
                } elseif ($action === 'renew') {
                    $client->action($router, self::MENU.'/renew', ['.id' => $id], $uid);
                } elseif ($action === 'destroy') {
                    $client->remove($router, self::MENU, $id, $uid);
                }
            }

            AuditLogger::record("ip.dhcp_client_batch_{$action}", $router, new: [
                'count' => count($ids),
                'action' => $action,
            ], routerId: $router->id);
        }, match ($action) {
            'enable' => count($ids).' DHCP client berhasil diaktifkan.',
            'disable' => count($ids).' DHCP client berhasil dinonaktifkan.',
            'release' => count($ids).' DHCP lease berhasil di-release.',
            'renew' => count($ids).' DHCP lease berhasil di-renew.',
            'destroy' => count($ids).' DHCP client berhasil dihapus.',
        });
    }
}

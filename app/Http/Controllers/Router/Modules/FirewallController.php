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
 * Firewall management: Filter Rules, NAT Rules, Mangle, Address Lists, and Connections.
 * Order matters in RouterOS firewall chains, so rule order is strictly preserved.
 */
class FirewallController extends Controller
{
    use InteractsWithRouterModule;

    private const FILTER = '/ip/firewall/filter';

    private const NAT = '/ip/firewall/nat';

    private const MANGLE = '/ip/firewall/mangle';

    private const ADDRESS_LIST = '/ip/firewall/address-list';

    private const CONNECTION = '/ip/firewall/connection';

    public function index(Request $request, Router $router, ResourceClient $client): Response
    {
        $this->ensureModuleAccess($router, Permissions::FIREWALL_VIEW);
        $router->load('capability');
        $uid = $request->user()->id;

        $filters = $this->deviceRead(fn () => $client->list($router, self::FILTER, userId: $uid));
        $nats = $this->deviceRead(fn () => $client->list($router, self::NAT, userId: $uid));
        $mangles = $this->deviceRead(fn () => $client->list($router, self::MANGLE, userId: $uid));
        $addressLists = $this->deviceRead(fn () => $client->list($router, self::ADDRESS_LIST, userId: $uid));
        $connections = $this->deviceRead(fn () => array_slice($client->list($router, self::CONNECTION, userId: $uid), 0, 100));
        $interfaces = $this->deviceRead(fn () => array_map(fn ($i) => $i['name'] ?? '', $client->list($router, '/interface', userId: $uid)));

        return Inertia::render('Routers/Modules/Firewall', [
            'router' => RouterPresenter::header($router),
            'rules' => $filters['data'], // backwards compatibility with existing props
            'filterRules' => $filters['data'],
            'natRules' => $nats['data'],
            'mangleRules' => $mangles['data'],
            'addressLists' => $addressLists['data'],
            'connections' => $connections['data'],
            'interfaces' => $interfaces['data'],
            'deviceError' => $filters['error'] ?? $nats['error'] ?? $mangles['error'] ?? $addressLists['error'],
            'can' => ['manage' => app(RouterAccess::class)->allows($request->user(), Permissions::FIREWALL_MANAGE, $router)],
        ]);
    }

    // ── Filter Rules ────────────────────────────────────────────────────

    public function store(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::FIREWALL_MANAGE);
        $data = $request->validate([
            'chain' => ['required', 'string', 'max:32'],
            'action' => ['required', 'string', 'max:32'],
            'protocol' => ['nullable', 'string', 'max:16'],
            'src_address' => ['nullable', 'string', 'max:64'],
            'dst_address' => ['nullable', 'string', 'max:64'],
            'dst_port' => ['nullable', 'string', 'max:64'],
            'in_interface' => ['nullable', 'string', 'max:64'],
            'out_interface' => ['nullable', 'string', 'max:64'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = ['chain' => $data['chain'], 'action' => $data['action']];
        foreach (['protocol' => 'protocol', 'src_address' => 'src-address', 'dst_address' => 'dst-address', 'dst_port' => 'dst-port', 'in_interface' => 'in-interface', 'out_interface' => 'out-interface', 'comment' => 'comment'] as $in => $ros) {
            if (filled($data[$in] ?? null)) {
                $payload[$ros] = $data[$in];
            }
        }

        return $this->deviceAction(function () use ($client, $router, $payload, $request) {
            $client->add($router, self::FILTER, $payload, $request->user()->id);
            AuditLogger::record('firewall.filter_add', $router, new: ['chain' => $payload['chain'], 'action' => $payload['action']], routerId: $router->id);
        }, 'Firewall filter rule added.');
    }

    public function toggle(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::FIREWALL_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $disable = $request->boolean('disable');

        return $this->deviceAction(function () use ($client, $router, $id, $disable, $request) {
            $disable
                ? $client->disable($router, self::FILTER, $id, $request->user()->id)
                : $client->enable($router, self::FILTER, $id, $request->user()->id);
            AuditLogger::record('firewall.filter_toggle', $router, new: ['id' => $id, 'disabled' => $disable], routerId: $router->id);
        }, $disable ? 'Rule disabled.' : 'Rule enabled.');
    }

    public function destroy(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::FIREWALL_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, self::FILTER, $id, $request->user()->id);
            AuditLogger::record('firewall.filter_remove', $router, old: ['id' => $id], routerId: $router->id);
        }, 'Rule removed.');
    }

    public function batchFilters(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::FIREWALL_MANAGE);
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
                    $client->enable($router, self::FILTER, $id, $uid);
                } elseif ($action === 'disable') {
                    $client->disable($router, self::FILTER, $id, $uid);
                } elseif ($action === 'destroy') {
                    $client->remove($router, self::FILTER, $id, $uid);
                }
            }

            AuditLogger::record("firewall.filter_batch_{$action}", $router, new: [
                'count' => count($ids),
                'action' => $action,
            ], routerId: $router->id);
        }, match ($action) {
            'enable' => count($ids).' firewall rule berhasil diaktifkan.',
            'disable' => count($ids).' firewall rule berhasil dinonaktifkan.',
            'destroy' => count($ids).' firewall rule berhasil dihapus.',
        });
    }

    public function resetFilterCounters(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::FIREWALL_MANAGE);
        $id = $request->input('id');

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $params = filled($id) ? ['.id' => (string) $id] : [];
            $client->action($router, self::FILTER.'/reset-counters', $params, $request->user()->id);
            AuditLogger::record('firewall.filter_reset_counters', $router, new: ['id' => $id ?? 'all'], routerId: $router->id);
        }, filled($id) ? 'Counter firewall rule berhasil di-reset.' : 'Seluruh counter firewall rule berhasil di-reset.');
    }

    // ── NAT Rules ───────────────────────────────────────────────────────

    public function storeNat(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::FIREWALL_MANAGE);
        $data = $request->validate([
            'chain' => ['required', 'string', 'in:srcnat,dstnat'],
            'action' => ['required', 'string', 'max:32'],
            'protocol' => ['nullable', 'string', 'max:16'],
            'src_address' => ['nullable', 'string', 'max:64'],
            'dst_address' => ['nullable', 'string', 'max:64'],
            'dst_port' => ['nullable', 'string', 'max:64'],
            'to_addresses' => ['nullable', 'string', 'max:64'],
            'to_ports' => ['nullable', 'string', 'max:64'],
            'out_interface' => ['nullable', 'string', 'max:64'],
            'in_interface' => ['nullable', 'string', 'max:64'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = ['chain' => $data['chain'], 'action' => $data['action']];
        foreach ([
            'protocol' => 'protocol', 'src_address' => 'src-address', 'dst_address' => 'dst-address',
            'dst_port' => 'dst-port', 'to_addresses' => 'to-addresses', 'to_ports' => 'to-ports',
            'out_interface' => 'out-interface', 'in_interface' => 'in-interface', 'comment' => 'comment',
        ] as $in => $ros) {
            if (filled($data[$in] ?? null)) {
                $payload[$ros] = $data[$in];
            }
        }

        return $this->deviceAction(function () use ($client, $router, $payload, $request) {
            $client->add($router, self::NAT, $payload, $request->user()->id);
            AuditLogger::record('firewall.nat_add', $router, new: ['chain' => $payload['chain'], 'action' => $payload['action']], routerId: $router->id);
        }, 'Firewall NAT rule added.');
    }

    public function toggleNat(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::FIREWALL_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $disable = $request->boolean('disable');

        return $this->deviceAction(function () use ($client, $router, $id, $disable, $request) {
            $disable
                ? $client->disable($router, self::NAT, $id, $request->user()->id)
                : $client->enable($router, self::NAT, $id, $request->user()->id);
            AuditLogger::record('firewall.nat_toggle', $router, new: ['id' => $id, 'disabled' => $disable], routerId: $router->id);
        }, $disable ? 'NAT rule disabled.' : 'NAT rule enabled.');
    }

    public function destroyNat(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::FIREWALL_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, self::NAT, $id, $request->user()->id);
            AuditLogger::record('firewall.nat_remove', $router, old: ['id' => $id], routerId: $router->id);
        }, 'NAT rule removed.');
    }

    public function batchNat(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::FIREWALL_MANAGE);
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
                    $client->enable($router, self::NAT, $id, $uid);
                } elseif ($action === 'disable') {
                    $client->disable($router, self::NAT, $id, $uid);
                } elseif ($action === 'destroy') {
                    $client->remove($router, self::NAT, $id, $uid);
                }
            }

            AuditLogger::record("firewall.nat_batch_{$action}", $router, new: [
                'count' => count($ids),
                'action' => $action,
            ], routerId: $router->id);
        }, match ($action) {
            'enable' => count($ids).' NAT rule berhasil diaktifkan.',
            'disable' => count($ids).' NAT rule berhasil dinonaktifkan.',
            'destroy' => count($ids).' NAT rule berhasil dihapus.',
        });
    }

    public function resetNatCounters(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::FIREWALL_MANAGE);
        $id = $request->input('id');

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $params = filled($id) ? ['.id' => (string) $id] : [];
            $client->action($router, self::NAT.'/reset-counters', $params, $request->user()->id);
            AuditLogger::record('firewall.nat_reset_counters', $router, new: ['id' => $id ?? 'all'], routerId: $router->id);
        }, filled($id) ? 'Counter NAT rule berhasil di-reset.' : 'Seluruh counter NAT rule berhasil di-reset.');
    }

    // ── Mangle Rules ────────────────────────────────────────────────────

    public function storeMangle(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::FIREWALL_MANAGE);
        $data = $request->validate([
            'chain' => ['required', 'string', 'max:32'],
            'action' => ['required', 'string', 'max:32'],
            'new_routing_mark' => ['nullable', 'string', 'max:64'],
            'new_packet_mark' => ['nullable', 'string', 'max:64'],
            'new_connection_mark' => ['nullable', 'string', 'max:64'],
            'passthrough' => ['nullable', 'string', 'in:yes,no'],
            'src_address' => ['nullable', 'string', 'max:64'],
            'dst_address' => ['nullable', 'string', 'max:64'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = ['chain' => $data['chain'], 'action' => $data['action']];
        foreach ([
            'new_routing_mark' => 'new-routing-mark', 'new_packet_mark' => 'new-packet-mark',
            'new_connection_mark' => 'new-connection-mark', 'passthrough' => 'passthrough',
            'src_address' => 'src-address', 'dst_address' => 'dst-address', 'comment' => 'comment',
        ] as $in => $ros) {
            if (filled($data[$in] ?? null)) {
                $payload[$ros] = $data[$in];
            }
        }

        return $this->deviceAction(function () use ($client, $router, $payload, $request) {
            $client->add($router, self::MANGLE, $payload, $request->user()->id);
            AuditLogger::record('firewall.mangle_add', $router, new: ['chain' => $payload['chain']], routerId: $router->id);
        }, 'Mangle rule added.');
    }

    public function toggleMangle(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::FIREWALL_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $disable = $request->boolean('disable');

        return $this->deviceAction(function () use ($client, $router, $id, $disable, $request) {
            $disable
                ? $client->disable($router, self::MANGLE, $id, $request->user()->id)
                : $client->enable($router, self::MANGLE, $id, $request->user()->id);
            AuditLogger::record('firewall.mangle_toggle', $router, new: ['id' => $id, 'disabled' => $disable], routerId: $router->id);
        }, $disable ? 'Mangle rule disabled.' : 'Mangle rule enabled.');
    }

    public function destroyMangle(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::FIREWALL_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, self::MANGLE, $id, $request->user()->id);
            AuditLogger::record('firewall.mangle_remove', $router, old: ['id' => $id], routerId: $router->id);
        }, 'Mangle rule removed.');
    }

    // ── Address Lists ───────────────────────────────────────────────────

    public function storeAddressList(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::FIREWALL_MANAGE);
        $data = $request->validate([
            'list' => ['required', 'string', 'max:64'],
            'address' => ['required', 'string', 'max:64'],
            'timeout' => ['nullable', 'string', 'max:32'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = ['list' => $data['list'], 'address' => $data['address']];
        if (filled($data['timeout'] ?? null)) {
            $payload['timeout'] = $data['timeout'];
        }
        if (filled($data['comment'] ?? null)) {
            $payload['comment'] = $data['comment'];
        }

        return $this->deviceAction(function () use ($client, $router, $payload, $request) {
            $client->add($router, self::ADDRESS_LIST, $payload, $request->user()->id);
            AuditLogger::record('firewall.address_list_add', $router, new: ['list' => $payload['list'], 'address' => $payload['address']], routerId: $router->id);
        }, 'Address list entry added.');
    }

    public function toggleAddressList(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::FIREWALL_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $disable = $request->boolean('disable');

        return $this->deviceAction(function () use ($client, $router, $id, $disable, $request) {
            $disable
                ? $client->disable($router, self::ADDRESS_LIST, $id, $request->user()->id)
                : $client->enable($router, self::ADDRESS_LIST, $id, $request->user()->id);
            AuditLogger::record('firewall.address_list_toggle', $router, new: ['id' => $id, 'disabled' => $disable], routerId: $router->id);
        }, $disable ? 'Address list entry disabled.' : 'Address list entry enabled.');
    }

    public function destroyAddressList(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::FIREWALL_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, self::ADDRESS_LIST, $id, $request->user()->id);
            AuditLogger::record('firewall.address_list_remove', $router, old: ['id' => $id], routerId: $router->id);
        }, 'Address list entry removed.');
    }
}

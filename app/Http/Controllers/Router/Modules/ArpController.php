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
 * IP ARP Table management.
 * View ARP bindings, convert dynamic entries to static, and add/remove static ARP.
 */
class ArpController extends Controller
{
    use InteractsWithRouterModule;

    private const MENU = '/ip/arp';

    public function index(Request $request, Router $router, ResourceClient $client): Response
    {
        $this->ensureModuleAccess($router, Permissions::IP_VIEW);
        $router->load('capability');
        $uid = $request->user()->id;

        $arp = $this->deviceRead(fn () => $client->list($router, self::MENU, userId: $uid));
        $interfaces = $this->deviceRead(fn () => array_map(fn ($i) => $i['name'] ?? '', $client->list($router, '/interface', userId: $uid)));

        return Inertia::render('Routers/Modules/Arp', [
            'router' => RouterPresenter::header($router),
            'entries' => $arp['data'],
            'interfaces' => $interfaces['data'],
            'deviceError' => $arp['error'],
            'can' => ['manage' => app(RouterAccess::class)->allows($request->user(), Permissions::IP_MANAGE, $router)],
        ]);
    }

    public function store(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $data = $request->validate([
            'address' => ['required', 'string', 'max:64'],
            'mac_address' => ['required', 'string', 'max:32'],
            'interface' => ['required', 'string', 'max:64'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = [
            'address' => $data['address'],
            'mac-address' => $data['mac_address'],
            'interface' => $data['interface'],
        ];
        if (filled($data['comment'] ?? null)) {
            $payload['comment'] = $data['comment'];
        }

        return $this->deviceAction(function () use ($client, $router, $payload, $request) {
            $client->add($router, self::MENU, $payload, $request->user()->id);
            AuditLogger::record('ip.arp_add', $router, new: ['address' => $payload['address'], 'mac' => $payload['mac-address']], routerId: $router->id);
        }, 'Static ARP entry added.');
    }

    public function makeStatic(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->action($router, self::MENU.'/make-static', ['.id' => $id], $request->user()->id);
            AuditLogger::record('ip.arp_make_static', $router, new: ['id' => $id], routerId: $router->id);
        }, 'ARP entry converted to static.');
    }

    public function toggle(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $disable = $request->boolean('disable');

        return $this->deviceAction(function () use ($client, $router, $id, $disable, $request) {
            $disable
                ? $client->disable($router, self::MENU, $id, $request->user()->id)
                : $client->enable($router, self::MENU, $id, $request->user()->id);
            AuditLogger::record('ip.arp_toggle', $router, new: ['id' => $id, 'disabled' => $disable], routerId: $router->id);
        }, $disable ? 'ARP entry disabled.' : 'ARP entry enabled.');
    }

    public function destroy(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, self::MENU, $id, $request->user()->id);
            AuditLogger::record('ip.arp_remove', $router, old: ['id' => $id], routerId: $router->id);
        }, 'ARP entry removed.');
    }

    public function batch(Request $request, Router $router, ResourceClient $client): RedirectResponse
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
                    $client->action($router, self::MENU.'/make-static', ['.id' => $id], $uid);
                } elseif ($action === 'enable') {
                    $client->enable($router, self::MENU, $id, $uid);
                } elseif ($action === 'disable') {
                    $client->disable($router, self::MENU, $id, $uid);
                } elseif ($action === 'destroy') {
                    $client->remove($router, self::MENU, $id, $uid);
                }
            }

            AuditLogger::record("ip.arp_batch_{$action}", $router, new: [
                'count' => count($ids),
                'action' => $action,
            ], routerId: $router->id);
        }, match ($action) {
            'make_static' => count($ids).' entri ARP berhasil dijadikan static.',
            'enable' => count($ids).' entri ARP berhasil diaktifkan.',
            'disable' => count($ids).' entri ARP berhasil dinonaktifkan.',
            'destroy' => count($ids).' entri ARP berhasil dihapus.',
        });
    }
}

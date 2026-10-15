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

class IpAddressController extends Controller
{
    use InteractsWithRouterModule;

    private const MENU = '/ip/address';

    public function index(Request $request, Router $router, ResourceClient $client): Response
    {
        $this->ensureModuleAccess($router, Permissions::IP_VIEW);
        $router->load('capability');

        $addresses = $this->deviceRead(fn () => $client->list($router, self::MENU, userId: $request->user()->id));
        // Interface list powers the add/edit dropdown.
        $interfaces = $this->deviceRead(fn () => array_map(
            fn ($i) => $i['name'] ?? '',
            $client->list($router, '/interface', userId: $request->user()->id),
        ));

        return Inertia::render('Routers/Modules/IpAddresses', [
            'router' => RouterPresenter::header($router),
            'addresses' => $addresses['data'],
            'interfaces' => array_values(array_filter($interfaces['data'])),
            'deviceError' => $addresses['error'],
            'can' => ['manage' => app(RouterAccess::class)->allows($request->user(), Permissions::IP_MANAGE, $router)],
        ]);
    }

    public function store(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $data = $this->validateAddress($request);

        return $this->deviceAction(function () use ($client, $router, $data, $request) {
            $client->add($router, self::MENU, $this->payload($data), $request->user()->id);
            AuditLogger::record('ip-address.add', $router, new: ['address' => $data['address']], routerId: $router->id);
        }, "Address {$data['address']} added.");
    }

    public function update(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $data = $this->validateAddress($request);

        return $this->deviceAction(function () use ($client, $router, $id, $data, $request) {
            $client->set($router, self::MENU, $id, $this->payload($data), $request->user()->id);
            AuditLogger::record('ip-address.set', $router, new: ['id' => $id, 'address' => $data['address']], routerId: $router->id);
        }, 'Address updated.');
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
        }, $disable ? 'Address disabled.' : 'Address enabled.');
    }

    public function destroy(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, self::MENU, $id, $request->user()->id);
            AuditLogger::record('ip-address.remove', $router, old: ['id' => $id], routerId: $router->id);
        }, 'Address removed.');
    }

    public function batch(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
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
                    $client->enable($router, self::MENU, $id, $uid);
                } elseif ($action === 'disable') {
                    $client->disable($router, self::MENU, $id, $uid);
                } elseif ($action === 'destroy') {
                    $client->remove($router, self::MENU, $id, $uid);
                }
            }

            AuditLogger::record("ip.addresses_batch_{$action}", $router, new: [
                'count' => count($ids),
                'action' => $action,
            ], routerId: $router->id);
        }, match ($action) {
            'enable' => count($ids).' alamat IP berhasil diaktifkan.',
            'disable' => count($ids).' alamat IP berhasil dinonaktifkan.',
            'destroy' => count($ids).' alamat IP berhasil dihapus.',
        });
    }

    /** @return array<string, mixed> */
    private function validateAddress(Request $request): array
    {
        return $request->validate([
            'address' => ['required', 'string', 'max:49'],   // e.g. 10.0.0.1/24
            'interface' => ['required', 'string', 'max:64'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /** @return array<string, scalar> */
    private function payload(array $data): array
    {
        $payload = ['address' => $data['address'], 'interface' => $data['interface']];
        if (filled($data['comment'] ?? null)) {
            $payload['comment'] = $data['comment'];
        }

        return $payload;
    }
}

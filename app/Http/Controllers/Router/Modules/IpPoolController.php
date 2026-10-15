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
 * IP Pool management.
 * View, create, update, and remove RouterOS IP pools and view used addresses.
 */
class IpPoolController extends Controller
{
    use InteractsWithRouterModule;

    private const MENU = '/ip/pool';

    private const USED_MENU = '/ip/pool/used';

    public function index(Request $request, Router $router, ResourceClient $client): Response
    {
        $this->ensureModuleAccess($router, Permissions::IP_VIEW);
        $router->load('capability');
        $uid = $request->user()->id;

        $pools = $this->deviceRead(fn () => $client->list($router, self::MENU, userId: $uid));
        $used = $this->deviceRead(fn () => $client->list($router, self::USED_MENU, userId: $uid));

        return Inertia::render('Routers/Modules/IpPool', [
            'router' => RouterPresenter::header($router),
            'pools' => $pools['data'],
            'used' => $used['data'],
            'deviceError' => $pools['error'],
            'can' => ['manage' => app(RouterAccess::class)->allows($request->user(), Permissions::IP_MANAGE, $router)],
        ]);
    }

    public function store(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'ranges' => ['required', 'string', 'max:255'],
            'next_pool' => ['nullable', 'string', 'max:64'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = [
            'name' => $data['name'],
            'ranges' => $data['ranges'],
        ];

        if (filled($data['next_pool'] ?? null) && $data['next_pool'] !== 'none') {
            $payload['next-pool'] = $data['next_pool'];
        }

        if (filled($data['comment'] ?? null)) {
            $payload['comment'] = $data['comment'];
        }

        return $this->deviceAction(function () use ($client, $router, $payload, $request, $data) {
            $client->add($router, self::MENU, $payload, $request->user()->id);
            AuditLogger::record('ip.pool_create', $router, new: ['name' => $data['name'], 'ranges' => $data['ranges']], routerId: $router->id);
        }, 'IP pool created successfully.');
    }

    public function update(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $data = $request->validate([
            'id' => ['required', 'string'],
            'name' => ['required', 'string', 'max:64'],
            'ranges' => ['required', 'string', 'max:255'],
            'next_pool' => ['nullable', 'string', 'max:64'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = [
            'name' => $data['name'],
            'ranges' => $data['ranges'],
            'next-pool' => (filled($data['next_pool'] ?? null) && $data['next_pool'] !== 'none') ? $data['next_pool'] : 'none',
        ];

        if (isset($data['comment'])) {
            $payload['comment'] = (string) $data['comment'];
        }

        return $this->deviceAction(function () use ($client, $router, $data, $payload, $request) {
            $client->set($router, self::MENU, $data['id'], $payload, $request->user()->id);
            AuditLogger::record('ip.pool_update', $router, new: ['name' => $data['name'], 'ranges' => $data['ranges']], routerId: $router->id);
        }, 'IP pool updated successfully.');
    }

    public function destroy(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $data = $request->validate([
            'id' => ['required', 'string'],
        ]);

        return $this->deviceAction(function () use ($client, $router, $data, $request) {
            $client->remove($router, self::MENU, $data['id'], $request->user()->id);
            AuditLogger::record('ip.pool_delete', $router, new: ['id' => $data['id']], routerId: $router->id);
        }, 'IP pool removed.');
    }

    public function batch(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $data = $request->validate([
            'action' => ['required', 'string', 'in:destroy'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'string'],
        ]);

        $ids = $data['ids'];
        $uid = $request->user()->id;

        return $this->deviceAction(function () use ($client, $router, $ids, $uid) {
            foreach ($ids as $id) {
                $client->remove($router, self::MENU, $id, $uid);
            }

            AuditLogger::record('ip.pool_batch_destroy', $router, new: [
                'count' => count($ids),
            ], routerId: $router->id);
        }, count($ids).' IP pool berhasil dihapus.');
    }
}

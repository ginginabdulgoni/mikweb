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

/** Static IP routes (/ip/route). */
class RouteController extends Controller
{
    use InteractsWithRouterModule;

    private const MENU = '/ip/route';

    public function index(Request $request, Router $router, ResourceClient $client): Response
    {
        $this->ensureModuleAccess($router, Permissions::IP_VIEW);
        $router->load('capability');

        $routes = $this->deviceRead(fn () => $client->list($router, self::MENU, userId: $request->user()->id));

        return Inertia::render('Routers/Modules/Routes', [
            'router' => RouterPresenter::header($router),
            'routes' => $routes['data'],
            'deviceError' => $routes['error'],
            'can' => ['manage' => app(RouterAccess::class)->allows($request->user(), Permissions::IP_MANAGE, $router)],
        ]);
    }

    public function store(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $data = $request->validate([
            'dst_address' => ['required', 'string', 'max:49'],
            'gateway' => ['required', 'string', 'max:64'],
            'distance' => ['nullable', 'integer', 'between:1,255'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = ['dst-address' => $data['dst_address'], 'gateway' => $data['gateway']];
        if (filled($data['distance'] ?? null)) {
            $payload['distance'] = (string) $data['distance'];
        }
        if (filled($data['comment'] ?? null)) {
            $payload['comment'] = $data['comment'];
        }

        return $this->deviceAction(function () use ($client, $router, $payload, $request) {
            $client->add($router, self::MENU, $payload, $request->user()->id);
            AuditLogger::record('route.add', $router, new: ['dst' => $payload['dst-address'], 'gateway' => $payload['gateway']], routerId: $router->id);
        }, "Route to {$data['dst_address']} added.");
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
        }, $disable ? 'Route disabled.' : 'Route enabled.');
    }

    public function destroy(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, self::MENU, $id, $request->user()->id);
            AuditLogger::record('route.remove', $router, old: ['id' => $id], routerId: $router->id);
        }, 'Route removed.');
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

            AuditLogger::record("ip.routes_batch_{$action}", $router, new: [
                'count' => count($ids),
                'action' => $action,
            ], routerId: $router->id);
        }, match ($action) {
            'enable' => count($ids).' routing rule berhasil diaktifkan.',
            'disable' => count($ids).' routing rule berhasil dinonaktifkan.',
            'destroy' => count($ids).' routing rule berhasil dihapus.',
        });
    }
}

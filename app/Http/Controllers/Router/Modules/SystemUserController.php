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
 * System Users management: RouterOS logins, permission groups, and active sessions.
 */
class SystemUserController extends Controller
{
    use InteractsWithRouterModule;

    private const USER = '/user';

    private const GROUP = '/user/group';

    private const ACTIVE = '/user/active';

    public function index(Request $request, Router $router, ResourceClient $client): Response
    {
        $this->ensureModuleAccess($router, Permissions::SYSTEM_VIEW);
        $router->load('capability');
        $uid = $request->user()->id;

        $users = $this->deviceRead(fn () => $client->list($router, self::USER, userId: $uid));
        $groups = $this->deviceRead(fn () => $client->list($router, self::GROUP, userId: $uid));
        $active = $this->deviceRead(fn () => $client->list($router, self::ACTIVE, userId: $uid));

        return Inertia::render('Routers/Modules/SystemUsers', [
            'router' => RouterPresenter::header($router),
            'users' => $users['data'],
            'groups' => $groups['data'],
            'activeUsers' => $active['data'],
            'deviceError' => $users['error'] ?? $groups['error'] ?? $active['error'],
            'can' => ['manage' => app(RouterAccess::class)->allows($request->user(), Permissions::SYSTEM_MANAGE, $router)],
        ]);
    }

    public function store(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::SYSTEM_MANAGE);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'group' => ['required', 'string', 'max:64'],
            'password' => ['nullable', 'string', 'min:4', 'max:128'],
            'address' => ['nullable', 'string', 'max:128'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = [
            'name' => $data['name'],
            'group' => $data['group'],
        ];
        if (filled($data['password'] ?? null)) {
            $payload['password'] = $data['password'];
        }
        if (filled($data['address'] ?? null)) {
            $payload['address'] = $data['address'];
        }
        if (filled($data['comment'] ?? null)) {
            $payload['comment'] = $data['comment'];
        }

        return $this->deviceAction(function () use ($client, $router, $payload, $request) {
            $client->add($router, self::USER, $payload, $request->user()->id);
            AuditLogger::record('system.user_add', $router, new: ['name' => $payload['name'], 'group' => $payload['group']], routerId: $router->id);
        }, 'Router user created.');
    }

    public function update(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::SYSTEM_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $data = $request->validate([
            'group' => ['required', 'string', 'max:64'],
            'password' => ['nullable', 'string', 'min:4', 'max:128'],
            'address' => ['nullable', 'string', 'max:128'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = [
            'group' => $data['group'],
            'address' => (string) ($data['address'] ?? ''),
            'comment' => (string) ($data['comment'] ?? ''),
        ];
        if (filled($data['password'] ?? null)) {
            $payload['password'] = $data['password'];
        }

        return $this->deviceAction(function () use ($client, $router, $id, $payload, $request) {
            $client->set($router, self::USER, $id, $payload, $request->user()->id);
            AuditLogger::record('system.user_update', $router, new: ['id' => $id], routerId: $router->id);
        }, 'Router user updated.');
    }

    public function toggle(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::SYSTEM_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $disable = $request->boolean('disable');

        return $this->deviceAction(function () use ($client, $router, $id, $disable, $request) {
            $disable
                ? $client->disable($router, self::USER, $id, $request->user()->id)
                : $client->enable($router, self::USER, $id, $request->user()->id);
            AuditLogger::record('system.user_toggle', $router, new: ['id' => $id, 'disabled' => $disable], routerId: $router->id);
        }, $disable ? 'User disabled.' : 'User enabled.');
    }

    public function destroy(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::SYSTEM_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, self::USER, $id, $request->user()->id);
            AuditLogger::record('system.user_remove', $router, old: ['id' => $id], routerId: $router->id);
        }, 'Router user removed.');
    }

    public function batch(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::SYSTEM_MANAGE);
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
                    $client->enable($router, self::USER, $id, $uid);
                } elseif ($action === 'disable') {
                    $client->disable($router, self::USER, $id, $uid);
                } elseif ($action === 'destroy') {
                    $client->remove($router, self::USER, $id, $uid);
                }
            }

            AuditLogger::record("system.user_batch_{$action}", $router, new: [
                'count' => count($ids),
                'action' => $action,
            ], routerId: $router->id);
        }, match ($action) {
            'enable' => count($ids).' user berhasil diaktifkan.',
            'disable' => count($ids).' user berhasil dinonaktifkan.',
            'destroy' => count($ids).' user berhasil dihapus.',
        });
    }
}

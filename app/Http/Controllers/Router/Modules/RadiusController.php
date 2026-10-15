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
 * RouterOS RADIUS Client configuration (/radius).
 * Manages external AAA servers used for Hotspot, PPPoE, and administrative authentication.
 */
class RadiusController extends Controller
{
    use InteractsWithRouterModule;

    private const MENU = '/radius';

    public function index(Request $request, Router $router, ResourceClient $client): Response
    {
        $this->ensureModuleAccess($router, Permissions::SYSTEM_VIEW);
        $router->load('capability');
        $uid = $request->user()->id;

        $servers = $this->deviceRead(fn () => $client->list($router, self::MENU, userId: $uid));

        return Inertia::render('Routers/Modules/Radius', [
            'router' => RouterPresenter::header($router),
            'servers' => $servers['data'],
            'deviceError' => $servers['error'],
            'can' => ['manage' => app(RouterAccess::class)->allows($request->user(), Permissions::SYSTEM_MANAGE, $router)],
        ]);
    }

    public function store(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::SYSTEM_MANAGE);
        $data = $request->validate([
            'service' => ['required', 'string', 'max:128'],
            'address' => ['required', 'string', 'max:64'],
            'secret' => ['required', 'string', 'max:64'],
            'authentication_port' => ['nullable', 'integer', 'between:1,65535'],
            'accounting_port' => ['nullable', 'integer', 'between:1,65535'],
            'timeout' => ['nullable', 'string', 'max:32'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = [
            'service' => $data['service'],
            'address' => $data['address'],
            'secret' => $data['secret'],
            'authentication-port' => (string) ($data['authentication_port'] ?? 1812),
            'accounting-port' => (string) ($data['accounting_port'] ?? 1813),
        ];

        if (filled($data['timeout'] ?? null)) {
            $payload['timeout'] = $data['timeout'];
        }
        if (filled($data['comment'] ?? null)) {
            $payload['comment'] = $data['comment'];
        }

        return $this->deviceAction(function () use ($client, $router, $payload, $request, $data) {
            $client->add($router, self::MENU, $payload, $request->user()->id);
            AuditLogger::record('radius.server_add', $router, new: ['address' => $data['address'], 'service' => $data['service']], routerId: $router->id);
        }, "RADIUS server '{$data['address']}' added.");
    }

    public function update(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::SYSTEM_MANAGE);
        $data = $request->validate([
            'id' => ['required', 'string'],
            'service' => ['required', 'string', 'max:128'],
            'address' => ['required', 'string', 'max:64'],
            'secret' => ['nullable', 'string', 'max:64'],
            'authentication_port' => ['nullable', 'integer', 'between:1,65535'],
            'accounting_port' => ['nullable', 'integer', 'between:1,65535'],
            'timeout' => ['nullable', 'string', 'max:32'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = [
            'service' => $data['service'],
            'address' => $data['address'],
            'authentication-port' => (string) ($data['authentication_port'] ?? 1812),
            'accounting-port' => (string) ($data['accounting_port'] ?? 1813),
        ];

        if (filled($data['secret'] ?? null)) {
            $payload['secret'] = $data['secret'];
        }
        if (isset($data['timeout'])) {
            $payload['timeout'] = (string) $data['timeout'];
        }
        if (isset($data['comment'])) {
            $payload['comment'] = (string) $data['comment'];
        }

        return $this->deviceAction(function () use ($client, $router, $data, $payload, $request) {
            $client->set($router, self::MENU, $data['id'], $payload, $request->user()->id);
            AuditLogger::record('radius.server_update', $router, new: ['id' => $data['id'], 'address' => $data['address']], routerId: $router->id);
        }, 'RADIUS server updated.');
    }

    public function toggle(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::SYSTEM_MANAGE);
        $data = $request->validate([
            'id' => ['required', 'string'],
            'disable' => ['required', 'boolean'],
        ]);

        $verb = $data['disable'] ? 'disable' : 'enable';

        return $this->deviceAction(function () use ($client, $router, $data, $verb, $request) {
            $data['disable']
                ? $client->disable($router, self::MENU, $data['id'], $request->user()->id)
                : $client->enable($router, self::MENU, $data['id'], $request->user()->id);
            AuditLogger::record("radius.server_{$verb}", $router, new: ['id' => $data['id']], routerId: $router->id);
        }, "RADIUS server {$verb}d.");
    }

    public function destroy(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::SYSTEM_MANAGE);
        $data = $request->validate([
            'id' => ['required', 'string'],
        ]);

        return $this->deviceAction(function () use ($client, $router, $data, $request) {
            $client->remove($router, self::MENU, $data['id'], $request->user()->id);
            AuditLogger::record('radius.server_delete', $router, new: ['id' => $data['id']], routerId: $router->id);
        }, 'RADIUS server removed.');
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
                    $client->enable($router, self::MENU, $id, $uid);
                } elseif ($action === 'disable') {
                    $client->disable($router, self::MENU, $id, $uid);
                } elseif ($action === 'destroy') {
                    $client->remove($router, self::MENU, $id, $uid);
                }
            }

            AuditLogger::record("radius.batch_{$action}", $router, new: [
                'count' => count($ids),
                'action' => $action,
            ], routerId: $router->id);
        }, match ($action) {
            'enable' => count($ids).' RADIUS server berhasil diaktifkan.',
            'disable' => count($ids).' RADIUS server berhasil dinonaktifkan.',
            'destroy' => count($ids).' RADIUS server berhasil dihapus.',
        });
    }
}

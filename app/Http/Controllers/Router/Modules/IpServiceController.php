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
 * IP Services management (api, winbox, www, ssh, telnet, ftp).
 */
class IpServiceController extends Controller
{
    use InteractsWithRouterModule;

    private const MENU = '/ip/service';

    public function index(Request $request, Router $router, ResourceClient $client): Response
    {
        $this->ensureModuleAccess($router, Permissions::IP_VIEW);
        $router->load('capability');
        $uid = $request->user()->id;

        $services = $this->deviceRead(fn () => $client->list($router, self::MENU, userId: $uid));

        return Inertia::render('Routers/Modules/IpServices', [
            'router' => RouterPresenter::header($router),
            'services' => $services['data'],
            'deviceError' => $services['error'],
            'can' => ['manage' => app(RouterAccess::class)->allows($request->user(), Permissions::IP_MANAGE, $router)],
        ]);
    }

    public function update(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $data = $request->validate([
            'port' => ['required', 'integer', 'between:1,65535'],
            'address' => ['nullable', 'string', 'max:128'],
        ]);

        $payload = [
            'port' => (string) $data['port'],
            'address' => (string) ($data['address'] ?? ''),
        ];

        return $this->deviceAction(function () use ($client, $router, $id, $payload, $request) {
            $client->set($router, self::MENU, $id, $payload, $request->user()->id);
            AuditLogger::record('ip.service_update', $router, new: ['id' => $id, 'port' => $payload['port']], routerId: $router->id);
        }, 'IP service updated.');
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
            AuditLogger::record('ip.service_toggle', $router, new: ['id' => $id, 'disabled' => $disable], routerId: $router->id);
        }, $disable ? 'Service disabled.' : 'Service enabled.');
    }

    public function batch(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $data = $request->validate([
            'action' => ['required', 'string', 'in:enable,disable'],
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
                }
            }

            AuditLogger::record("ip.service_batch_{$action}", $router, new: [
                'count' => count($ids),
                'action' => $action,
            ], routerId: $router->id);
        }, match ($action) {
            'enable' => count($ids).' service berhasil diaktifkan.',
            'disable' => count($ids).' service berhasil dinonaktifkan.',
        });
    }
}

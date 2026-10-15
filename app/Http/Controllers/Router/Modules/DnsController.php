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
 * IP DNS management: DNS settings, cache overview, and static DNS entries.
 */
class DnsController extends Controller
{
    use InteractsWithRouterModule;

    private const SETTINGS = '/ip/dns';

    private const STATIC_DNS = '/ip/dns/static';

    public function index(Request $request, Router $router, ResourceClient $client): Response
    {
        $this->ensureModuleAccess($router, Permissions::IP_VIEW);
        $router->load('capability');
        $uid = $request->user()->id;

        $settings = $this->deviceRead(fn () => $client->list($router, self::SETTINGS, userId: $uid)[0] ?? []);
        $static = $this->deviceRead(fn () => $client->list($router, self::STATIC_DNS, userId: $uid));

        return Inertia::render('Routers/Modules/Dns', [
            'router' => RouterPresenter::header($router),
            'dnsSettings' => $settings['data'],
            'staticEntries' => $static['data'],
            'deviceError' => $settings['error'] ?? $static['error'],
            'can' => ['manage' => app(RouterAccess::class)->allows($request->user(), Permissions::IP_MANAGE, $router)],
        ]);
    }

    public function updateSettings(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $data = $request->validate([
            'servers' => ['required', 'string', 'max:255'],
            'allow_remote_requests' => ['nullable', 'boolean'],
            'cache_size' => ['nullable', 'string', 'max:32'],
        ]);

        $payload = [
            'servers' => $data['servers'],
            'allow-remote-requests' => ($data['allow_remote_requests'] ?? false) ? 'yes' : 'no',
        ];
        if (filled($data['cache_size'] ?? null)) {
            $payload['cache-size'] = $data['cache_size'];
        }

        return $this->deviceAction(function () use ($client, $router, $payload, $request) {
            $client->action($router, self::SETTINGS.'/set', $payload, $request->user()->id);
            AuditLogger::record('ip.dns_settings', $router, new: ['servers' => $payload['servers']], routerId: $router->id);
        }, 'DNS settings updated.');
    }

    public function storeStatic(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'address' => ['required', 'string', 'max:64'],
            'ttl' => ['nullable', 'string', 'max:32'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = [
            'name' => $data['name'],
            'address' => $data['address'],
            'ttl' => (string) ($data['ttl'] ?: '1d'),
        ];
        if (filled($data['comment'] ?? null)) {
            $payload['comment'] = $data['comment'];
        }

        return $this->deviceAction(function () use ($client, $router, $payload, $request) {
            $client->add($router, self::STATIC_DNS, $payload, $request->user()->id);
            AuditLogger::record('ip.dns_static_add', $router, new: ['name' => $payload['name'], 'address' => $payload['address']], routerId: $router->id);
        }, 'Static DNS entry added.');
    }

    public function updateStatic(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'address' => ['required', 'string', 'max:64'],
            'ttl' => ['nullable', 'string', 'max:32'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = [
            'name' => $data['name'],
            'address' => $data['address'],
            'ttl' => (string) ($data['ttl'] ?: '1d'),
            'comment' => (string) ($data['comment'] ?? ''),
        ];

        return $this->deviceAction(function () use ($client, $router, $id, $payload, $request) {
            $client->set($router, self::STATIC_DNS, $id, $payload, $request->user()->id);
            AuditLogger::record('ip.dns_static_update', $router, new: ['id' => $id], routerId: $router->id);
        }, 'Static DNS entry updated.');
    }

    public function toggleStatic(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $disable = $request->boolean('disable');

        return $this->deviceAction(function () use ($client, $router, $id, $disable, $request) {
            $disable
                ? $client->disable($router, self::STATIC_DNS, $id, $request->user()->id)
                : $client->enable($router, self::STATIC_DNS, $id, $request->user()->id);
            AuditLogger::record('ip.dns_static_toggle', $router, new: ['id' => $id, 'disabled' => $disable], routerId: $router->id);
        }, $disable ? 'DNS entry disabled.' : 'DNS entry enabled.');
    }

    public function destroyStatic(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::IP_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, self::STATIC_DNS, $id, $request->user()->id);
            AuditLogger::record('ip.dns_static_remove', $router, old: ['id' => $id], routerId: $router->id);
        }, 'Static DNS entry removed.');
    }

    public function batchStatic(Request $request, Router $router, ResourceClient $client): RedirectResponse
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
                    $client->enable($router, self::STATIC_DNS, $id, $uid);
                } elseif ($action === 'disable') {
                    $client->disable($router, self::STATIC_DNS, $id, $uid);
                } elseif ($action === 'destroy') {
                    $client->remove($router, self::STATIC_DNS, $id, $uid);
                }
            }

            AuditLogger::record("ip.dns_static_batch_{$action}", $router, new: [
                'count' => count($ids),
                'action' => $action,
            ], routerId: $router->id);
        }, match ($action) {
            'enable' => count($ids).' entri DNS static berhasil diaktifkan.',
            'disable' => count($ids).' entri DNS static berhasil dinonaktifkan.',
            'destroy' => count($ids).' entri DNS static berhasil dihapus.',
        });
    }
}

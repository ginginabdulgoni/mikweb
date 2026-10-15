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
 * System: Resources, Logs, Scripts, Schedulers, Identity, and Reboot.
 */
class SystemController extends Controller
{
    use InteractsWithRouterModule;

    public function index(Request $request, Router $router, ResourceClient $client): Response
    {
        $this->ensureModuleAccess($router, Permissions::SYSTEM_VIEW);
        $router->load('capability');
        $uid = $request->user()->id;

        $logs = $this->deviceRead(fn () => array_slice(array_reverse($client->list($router, '/log', userId: $uid)), 0, 100));
        $scripts = $this->deviceRead(fn () => $client->list($router, '/system/script', userId: $uid));
        $schedulers = $this->deviceRead(fn () => $client->list($router, '/system/scheduler', userId: $uid));
        $resource = $this->deviceRead(fn () => $client->list($router, '/system/resource', userId: $uid)[0] ?? []);
        $identity = $this->deviceRead(fn () => $client->list($router, '/system/identity', userId: $uid)[0] ?? ['name' => $router->name]);

        return Inertia::render('Routers/Modules/System', [
            'router' => RouterPresenter::header($router),
            'logs' => $logs['data'],
            'scripts' => $scripts['data'],
            'schedulers' => $schedulers['data'],
            'resource' => $resource['data'],
            'identity' => $identity['data'],
            'deviceError' => $logs['error'] ?? $scripts['error'] ?? $schedulers['error'],
            'can' => ['manage' => app(RouterAccess::class)->allows($request->user(), Permissions::SYSTEM_MANAGE, $router)],
        ]);
    }

    public function runScript(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::SYSTEM_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->action($router, '/system/script/run', ['.id' => $id], $request->user()->id);
            AuditLogger::record('system.script_run', $router, new: ['id' => $id], routerId: $router->id);
        }, 'Script executed.');
    }

    public function storeScript(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::SYSTEM_MANAGE);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'source' => ['required', 'string', 'max:4096'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = ['name' => $data['name'], 'source' => $data['source']];
        if (filled($data['comment'] ?? null)) {
            $payload['comment'] = $data['comment'];
        }

        return $this->deviceAction(function () use ($client, $router, $payload, $request) {
            $client->add($router, '/system/script', $payload, $request->user()->id);
            AuditLogger::record('system.script_add', $router, new: ['name' => $payload['name']], routerId: $router->id);
        }, 'Script added.');
    }

    public function destroyScript(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::SYSTEM_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, '/system/script', $id, $request->user()->id);
            AuditLogger::record('system.script_remove', $router, old: ['id' => $id], routerId: $router->id);
        }, 'Script removed.');
    }

    public function storeScheduler(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::SYSTEM_MANAGE);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'interval' => ['required', 'string', 'max:32'],
            'on_event' => ['required', 'string', 'max:1024'],
            'start_time' => ['nullable', 'string', 'max:32'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = [
            'name' => $data['name'],
            'interval' => $data['interval'],
            'on-event' => $data['on_event'],
            'start-time' => (string) ($data['start_time'] ?? 'startup'),
        ];
        if (filled($data['comment'] ?? null)) {
            $payload['comment'] = $data['comment'];
        }

        return $this->deviceAction(function () use ($client, $router, $payload, $request) {
            $client->add($router, '/system/scheduler', $payload, $request->user()->id);
            AuditLogger::record('system.scheduler_add', $router, new: ['name' => $payload['name']], routerId: $router->id);
        }, 'Scheduler created.');
    }

    public function toggleScheduler(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::SYSTEM_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $disable = $request->boolean('disable');

        return $this->deviceAction(function () use ($client, $router, $id, $disable, $request) {
            $disable
                ? $client->disable($router, '/system/scheduler', $id, $request->user()->id)
                : $client->enable($router, '/system/scheduler', $id, $request->user()->id);
            AuditLogger::record('system.scheduler_toggle', $router, new: ['id' => $id, 'disabled' => $disable], routerId: $router->id);
        }, $disable ? 'Scheduler disabled.' : 'Scheduler enabled.');
    }

    public function destroyScheduler(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::SYSTEM_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, '/system/scheduler', $id, $request->user()->id);
            AuditLogger::record('system.scheduler_remove', $router, old: ['id' => $id], routerId: $router->id);
        }, 'Scheduler removed.');
    }

    public function updateIdentity(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::SYSTEM_MANAGE);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
        ]);

        return $this->deviceAction(function () use ($client, $router, $data, $request) {
            $client->action($router, '/system/identity/set', ['name' => $data['name']], $request->user()->id);
            $router->update(['name' => $data['name']]);
            AuditLogger::record('system.identity_set', $router, new: ['name' => $data['name']], routerId: $router->id);
        }, 'Router identity updated.');
    }

    public function reboot(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::SYSTEM_MANAGE);

        return $this->deviceAction(function () use ($client, $router, $request) {
            try {
                $client->action($router, '/system/reboot', [], $request->user()->id);
            } catch (\Throwable $e) {
                $msg = strtolower($e->getMessage());
                if (! str_contains($msg, 'reset') && ! str_contains($msg, 'closed') && ! str_contains($msg, 'broken') && ! str_contains($msg, 'pipe')) {
                    throw $e;
                }
            }
            AuditLogger::record('system.reboot', $router, new: ['action' => 'reboot'], routerId: $router->id);
        }, 'Router reboot signal sent.');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Router\Modules;

use App\Domain\Audit\AuditLogger;
use App\Domain\Router\Data\RouterPresenter;
use App\Domain\Router\Models\Router;
use App\Http\Controllers\Controller;
use App\Services\RouterOS\Exceptions\RouterOsException;
use App\Services\RouterOS\ResourceClient;
use App\Support\Access\Permissions;
use App\Support\Access\RouterAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Network tools executed ON the router: ping, traceroute, and Netwatch host monitor (/tool/netwatch).
 */
class ToolsController extends Controller
{
    use InteractsWithRouterModule;

    private const NETWATCH_MENU = '/tool/netwatch';

    public function index(Request $request, Router $router, ResourceClient $client): Response
    {
        $this->ensureModuleAccess($router, Permissions::TOOLS_USE);
        $router->load('capability');
        $uid = $request->user()->id;

        $netwatch = $this->deviceRead(fn () => $client->list($router, self::NETWATCH_MENU, userId: $uid));
        $interfaces = $this->deviceRead(fn () => array_map(fn ($i) => $i['name'] ?? '', $client->list($router, '/interface', userId: $uid)));

        return Inertia::render('Routers/Modules/Tools', [
            'router' => RouterPresenter::header($router),
            'netwatch' => $netwatch['data'],
            'interfaces' => $interfaces['data'],
            'deviceError' => $netwatch['error'],
            'can' => ['manage' => app(RouterAccess::class)->allows($request->user(), Permissions::ROUTER_MANAGE, $router)],
        ]);
    }

    public function torch(Request $request, Router $router, ResourceClient $client): JsonResponse
    {
        $this->ensureModuleAccess($router, Permissions::TOOLS_USE);
        $data = $request->validate([
            'interface' => ['required', 'string', 'max:255'],
        ]);

        try {
            $rows = $client->action($router, '/tool/torch', [
                'interface' => $data['interface'],
                'duration' => '3s',
                'once' => 'true',
            ], $request->user()->id);
        } catch (RouterOsException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        AuditLogger::record('tools.torch', $router, new: ['interface' => $data['interface']], routerId: $router->id);

        return response()->json([
            'success' => true,
            'interface' => $data['interface'],
            'count' => count($rows),
            'rows' => $rows,
        ]);
    }

    public function ping(Request $request, Router $router, ResourceClient $client): JsonResponse
    {
        $this->ensureModuleAccess($router, Permissions::TOOLS_USE);
        $data = $request->validate([
            'address' => ['required', 'string', 'max:255'],
            'count' => ['nullable', 'integer', 'between:1,10'],
        ]);
        $count = (string) ($data['count'] ?? 4);

        try {
            $rows = $client->action($router, '/ping', [
                'address' => $data['address'],
                'count' => $count,
            ], $request->user()->id);
        } catch (RouterOsException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        AuditLogger::record('tools.ping', $router, new: ['address' => $data['address']], routerId: $router->id);

        return response()->json(['rows' => $rows]);
    }

    public function traceroute(Request $request, Router $router, ResourceClient $client): JsonResponse
    {
        $this->ensureModuleAccess($router, Permissions::TOOLS_USE);
        $data = $request->validate([
            'address' => ['required', 'string', 'max:255'],
        ]);

        try {
            $rows = $client->action($router, '/tool/traceroute', [
                'address' => $data['address'],
                'count' => '1',
            ], $request->user()->id);
        } catch (RouterOsException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        AuditLogger::record('tools.traceroute', $router, new: ['address' => $data['address']], routerId: $router->id);

        return response()->json(['rows' => $rows]);
    }

    public function storeNetwatch(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::TOOLS_USE);
        $data = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'interval' => ['nullable', 'string', 'max:32'],
            'timeout' => ['nullable', 'string', 'max:32'],
            'up_script' => ['nullable', 'string', 'max:1000'],
            'down_script' => ['nullable', 'string', 'max:1000'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = ['host' => $data['host']];
        if (filled($data['interval'] ?? null)) {
            $payload['interval'] = $data['interval'];
        }
        if (filled($data['timeout'] ?? null)) {
            $payload['timeout'] = $data['timeout'];
        }
        if (filled($data['up_script'] ?? null)) {
            $payload['up-script'] = $data['up_script'];
        }
        if (filled($data['down_script'] ?? null)) {
            $payload['down-script'] = $data['down_script'];
        }
        if (filled($data['comment'] ?? null)) {
            $payload['comment'] = $data['comment'];
        }

        return $this->deviceAction(function () use ($client, $router, $payload, $request, $data) {
            $client->add($router, self::NETWATCH_MENU, $payload, $request->user()->id);
            AuditLogger::record('tools.netwatch_add', $router, new: ['host' => $data['host']], routerId: $router->id);
        }, "Netwatch monitor for '{$data['host']}' created.");
    }

    public function updateNetwatch(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::TOOLS_USE);
        $data = $request->validate([
            'id' => ['required', 'string'],
            'host' => ['required', 'string', 'max:255'],
            'interval' => ['nullable', 'string', 'max:32'],
            'timeout' => ['nullable', 'string', 'max:32'],
            'up_script' => ['nullable', 'string', 'max:1000'],
            'down_script' => ['nullable', 'string', 'max:1000'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = ['host' => $data['host']];
        if (isset($data['interval'])) {
            $payload['interval'] = $data['interval'];
        }
        if (isset($data['timeout'])) {
            $payload['timeout'] = $data['timeout'];
        }
        if (isset($data['up_script'])) {
            $payload['up-script'] = (string) $data['up_script'];
        }
        if (isset($data['down_script'])) {
            $payload['down-script'] = (string) $data['down_script'];
        }
        if (isset($data['comment'])) {
            $payload['comment'] = (string) $data['comment'];
        }

        return $this->deviceAction(function () use ($client, $router, $data, $payload, $request) {
            $client->set($router, self::NETWATCH_MENU, $data['id'], $payload, $request->user()->id);
            AuditLogger::record('tools.netwatch_update', $router, new: ['id' => $data['id'], 'host' => $data['host']], routerId: $router->id);
        }, 'Netwatch monitor updated.');
    }

    public function toggleNetwatch(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::TOOLS_USE);
        $data = $request->validate([
            'id' => ['required', 'string'],
            'disable' => ['required', 'boolean'],
        ]);

        $verb = $data['disable'] ? 'disable' : 'enable';

        return $this->deviceAction(function () use ($client, $router, $data, $verb, $request) {
            $data['disable']
                ? $client->disable($router, self::NETWATCH_MENU, $data['id'], $request->user()->id)
                : $client->enable($router, self::NETWATCH_MENU, $data['id'], $request->user()->id);
            AuditLogger::record("tools.netwatch_{$verb}", $router, new: ['id' => $data['id']], routerId: $router->id);
        }, "Netwatch monitor {$verb}d.");
    }

    public function destroyNetwatch(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::TOOLS_USE);
        $data = $request->validate([
            'id' => ['required', 'string'],
        ]);

        return $this->deviceAction(function () use ($client, $router, $data, $request) {
            $client->remove($router, self::NETWATCH_MENU, $data['id'], $request->user()->id);
            AuditLogger::record('tools.netwatch_delete', $router, new: ['id' => $data['id']], routerId: $router->id);
        }, 'Netwatch monitor removed.');
    }
}

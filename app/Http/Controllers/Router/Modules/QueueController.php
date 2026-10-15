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
 * Queue management: Simple Queues, Queue Tree, and Queue Types.
 * Essential for bandwidth management, traffic shaping, and QoS.
 */
class QueueController extends Controller
{
    use InteractsWithRouterModule;

    private const SIMPLE = '/queue/simple';

    private const TREE = '/queue/tree';

    private const TYPE = '/queue/type';

    public function index(Request $request, Router $router, ResourceClient $client): Response
    {
        $this->ensureModuleAccess($router, Permissions::QUEUE_VIEW);
        $router->load('capability');
        $uid = $request->user()->id;

        $simple = $this->deviceRead(fn () => $client->list($router, self::SIMPLE, userId: $uid));
        $tree = $this->deviceRead(fn () => $client->list($router, self::TREE, userId: $uid));
        $types = $this->deviceRead(fn () => $client->list($router, self::TYPE, userId: $uid));
        $interfaces = $this->deviceRead(fn () => array_map(fn ($i) => $i['name'] ?? '', $client->list($router, '/interface', userId: $uid)));

        return Inertia::render('Routers/Modules/Queues', [
            'router' => RouterPresenter::header($router),
            'simpleQueues' => $simple['data'],
            'queueTree' => $tree['data'],
            'queueTypes' => $types['data'],
            'interfaces' => $interfaces['data'] ?? [],
            'deviceError' => $simple['error'] ?? $tree['error'] ?? $types['error'],
            'can' => ['manage' => app(RouterAccess::class)->allows($request->user(), Permissions::QUEUE_MANAGE, $router)],
        ]);
    }

    public function store(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::QUEUE_MANAGE);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'target' => ['required', 'string', 'max:64'],
            'max_limit' => ['required', 'string', 'max:32'],
            'limit_at' => ['nullable', 'string', 'max:32'],
            'burst_limit' => ['nullable', 'string', 'max:32'],
            'priority' => ['nullable', 'string', 'max:16'],
            'parent' => ['nullable', 'string', 'max:64'],
            'queue_type' => ['nullable', 'string', 'max:64'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = [
            'name' => $data['name'],
            'target' => $data['target'],
            'max-limit' => $data['max_limit'],
        ];
        if (filled($data['limit_at'] ?? null)) {
            $payload['limit-at'] = $data['limit_at'];
        }
        if (filled($data['burst_limit'] ?? null)) {
            $payload['burst-limit'] = $data['burst_limit'];
        }
        if (filled($data['priority'] ?? null)) {
            $payload['priority'] = $data['priority'];
        }
        if (filled($data['parent'] ?? null) && $data['parent'] !== 'none') {
            $payload['parent'] = $data['parent'];
        }
        if (filled($data['queue_type'] ?? null) && $data['queue_type'] !== 'default') {
            $payload['queue'] = $data['queue_type'];
        }
        if (filled($data['comment'] ?? null)) {
            $payload['comment'] = $data['comment'];
        }

        return $this->deviceAction(function () use ($client, $router, $payload, $request) {
            $client->add($router, self::SIMPLE, $payload, $request->user()->id);
            AuditLogger::record('queue.simple_add', $router, new: ['name' => $payload['name'], 'target' => $payload['target']], routerId: $router->id);
        }, 'Simple queue created.');
    }

    public function update(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::QUEUE_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'target' => ['required', 'string', 'max:64'],
            'max_limit' => ['required', 'string', 'max:32'],
            'limit_at' => ['nullable', 'string', 'max:32'],
            'burst_limit' => ['nullable', 'string', 'max:32'],
            'priority' => ['nullable', 'string', 'max:16'],
            'parent' => ['nullable', 'string', 'max:64'],
            'queue_type' => ['nullable', 'string', 'max:64'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = [
            'name' => $data['name'],
            'target' => $data['target'],
            'max-limit' => $data['max_limit'],
            'limit-at' => (string) ($data['limit_at'] ?? ''),
            'burst-limit' => (string) ($data['burst_limit'] ?? ''),
            'priority' => (string) ($data['priority'] ?? '8/8'),
            'parent' => (string) ($data['parent'] ?? 'none'),
            'queue' => (string) ($data['queue_type'] ?? 'default/default'),
            'comment' => (string) ($data['comment'] ?? ''),
        ];

        return $this->deviceAction(function () use ($client, $router, $id, $payload, $request) {
            $client->set($router, self::SIMPLE, $id, $payload, $request->user()->id);
            AuditLogger::record('queue.simple_update', $router, new: ['name' => $payload['name']], routerId: $router->id);
        }, 'Simple queue updated.');
    }

    public function quickLimit(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::QUEUE_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $data = $request->validate([
            'max_limit' => ['required', 'string', 'max:32'],
            'burst_limit' => ['nullable', 'string', 'max:32'],
        ]);

        $payload = [
            'max-limit' => $data['max_limit'],
        ];
        if (filled($data['burst_limit'] ?? null)) {
            $payload['burst-limit'] = $data['burst_limit'];
        }

        return $this->deviceAction(function () use ($client, $router, $id, $payload, $request) {
            $client->set($router, self::SIMPLE, $id, $payload, $request->user()->id);
            AuditLogger::record('queue.simple_quick_limit', $router, new: ['id' => $id, 'max_limit' => $payload['max-limit']], routerId: $router->id);
        }, "Bandwidth limit updated to {$payload['max-limit']}.");
    }

    public function toggle(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::QUEUE_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $disable = $request->boolean('disable');

        return $this->deviceAction(function () use ($client, $router, $id, $disable, $request) {
            $disable
                ? $client->disable($router, self::SIMPLE, $id, $request->user()->id)
                : $client->enable($router, self::SIMPLE, $id, $request->user()->id);
            AuditLogger::record('queue.simple_toggle', $router, new: ['id' => $id, 'disabled' => $disable], routerId: $router->id);
        }, $disable ? 'Queue disabled.' : 'Queue enabled.');
    }

    public function destroy(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::QUEUE_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, self::SIMPLE, $id, $request->user()->id);
            AuditLogger::record('queue.simple_remove', $router, old: ['id' => $id], routerId: $router->id);
        }, 'Queue removed.');
    }

    public function batchSimple(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::QUEUE_MANAGE);
        $data = $request->validate([
            'action' => ['required', 'string', 'in:enable,disable,change_limit,reset_counters,destroy'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'string'],
            'max_limit' => ['nullable', 'string', 'max:32', 'required_if:action,change_limit'],
        ]);

        $action = $data['action'];
        $ids = $data['ids'];
        $uid = $request->user()->id;

        return $this->deviceAction(function () use ($client, $router, $action, $ids, $data, $uid) {
            foreach ($ids as $id) {
                if ($action === 'enable') {
                    $client->enable($router, self::SIMPLE, $id, $uid);
                } elseif ($action === 'disable') {
                    $client->disable($router, self::SIMPLE, $id, $uid);
                } elseif ($action === 'change_limit') {
                    $client->set($router, self::SIMPLE, $id, ['max-limit' => $data['max_limit']], $uid);
                } elseif ($action === 'reset_counters') {
                    $client->action($router, self::SIMPLE.'/reset-counters', ['.id' => $id], $uid);
                } elseif ($action === 'destroy') {
                    $client->remove($router, self::SIMPLE, $id, $uid);
                }
            }

            AuditLogger::record("queue.simple_batch_{$action}", $router, new: [
                'count' => count($ids),
                'action' => $action,
                'max_limit' => $data['max_limit'] ?? null,
            ], routerId: $router->id);
        }, match ($action) {
            'enable' => count($ids).' queue berhasil diaktifkan.',
            'disable' => count($ids).' queue berhasil dinonaktifkan.',
            'change_limit' => count($ids)." queue berhasil diubah ke {$data['max_limit']}.",
            'reset_counters' => count($ids).' counter queue berhasil di-reset ke 0.',
            'destroy' => count($ids).' queue berhasil dihapus.',
        });
    }

    public function resetCounters(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::QUEUE_MANAGE);
        $id = $request->input('id');

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $params = filled($id) ? ['.id' => (string) $id] : [];
            $client->action($router, self::SIMPLE.'/reset-counters', $params, $request->user()->id);
            AuditLogger::record('queue.simple_reset_counters', $router, new: ['id' => $id ?? 'all'], routerId: $router->id);
        }, filled($id) ? 'Counter queue berhasil di-reset.' : 'Seluruh counter queue berhasil di-reset.');
    }

    public function storeTree(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::QUEUE_MANAGE);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'parent' => ['required', 'string', 'max:64'],
            'packet_mark' => ['nullable', 'string', 'max:64'],
            'max_limit' => ['nullable', 'string', 'max:32'],
            'limit_at' => ['nullable', 'string', 'max:32'],
            'priority' => ['nullable', 'string', 'max:16'],
        ]);

        $payload = ['name' => $data['name'], 'parent' => $data['parent']];
        if (filled($data['packet_mark'] ?? null)) {
            $payload['packet-mark'] = $data['packet_mark'];
        }
        if (filled($data['max_limit'] ?? null)) {
            $payload['max-limit'] = $data['max_limit'];
        }
        if (filled($data['limit_at'] ?? null)) {
            $payload['limit-at'] = $data['limit_at'];
        }
        if (filled($data['priority'] ?? null)) {
            $payload['priority'] = $data['priority'];
        }

        return $this->deviceAction(function () use ($client, $router, $payload, $request) {
            $client->add($router, self::TREE, $payload, $request->user()->id);
            AuditLogger::record('queue.tree_add', $router, new: ['name' => $payload['name']], routerId: $router->id);
        }, 'Queue tree node added.');
    }

    public function updateTree(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::QUEUE_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'parent' => ['required', 'string', 'max:64'],
            'packet_mark' => ['nullable', 'string', 'max:64'],
            'max_limit' => ['nullable', 'string', 'max:32'],
            'limit_at' => ['nullable', 'string', 'max:32'],
            'priority' => ['nullable', 'string', 'max:16'],
        ]);

        $payload = [
            'name' => $data['name'],
            'parent' => $data['parent'],
            'packet-mark' => (string) ($data['packet_mark'] ?? ''),
            'max-limit' => (string) ($data['max_limit'] ?? ''),
            'limit-at' => (string) ($data['limit_at'] ?? ''),
            'priority' => (string) ($data['priority'] ?? '8'),
        ];

        return $this->deviceAction(function () use ($client, $router, $id, $payload, $request) {
            $client->set($router, self::TREE, $id, $payload, $request->user()->id);
            AuditLogger::record('queue.tree_update', $router, new: ['name' => $payload['name']], routerId: $router->id);
        }, 'Queue tree node updated.');
    }

    public function toggleTree(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::QUEUE_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $disable = $request->boolean('disable');

        return $this->deviceAction(function () use ($client, $router, $id, $disable, $request) {
            $disable
                ? $client->disable($router, self::TREE, $id, $request->user()->id)
                : $client->enable($router, self::TREE, $id, $request->user()->id);
            AuditLogger::record('queue.tree_toggle', $router, new: ['id' => $id, 'disabled' => $disable], routerId: $router->id);
        }, $disable ? 'Queue tree node disabled.' : 'Queue tree node enabled.');
    }

    public function destroyTree(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::QUEUE_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, self::TREE, $id, $request->user()->id);
            AuditLogger::record('queue.tree_remove', $router, old: ['id' => $id], routerId: $router->id);
        }, 'Queue tree node removed.');
    }

    public function storeType(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::QUEUE_MANAGE);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'kind' => ['required', 'string', 'in:pcq,pfifo,bfifo,sfq,red'],
            'pcq_rate' => ['nullable', 'string', 'max:32'],
            'pcq_classifier' => ['nullable', 'string', 'max:64'],
        ]);

        $payload = ['name' => $data['name'], 'kind' => $data['kind']];
        if ($data['kind'] === 'pcq') {
            if (filled($data['pcq_rate'] ?? null)) {
                $payload['pcq-rate'] = $data['pcq_rate'];
            }
            if (filled($data['pcq_classifier'] ?? null)) {
                $payload['pcq-classifier'] = $data['pcq_classifier'];
            }
        }

        return $this->deviceAction(function () use ($client, $router, $payload, $request) {
            $client->add($router, self::TYPE, $payload, $request->user()->id);
            AuditLogger::record('queue.type_add', $router, new: ['name' => $payload['name']], routerId: $router->id);
        }, 'Queue type added.');
    }

    public function destroyType(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::QUEUE_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, self::TYPE, $id, $request->user()->id);
            AuditLogger::record('queue.type_remove', $router, old: ['id' => $id], routerId: $router->id);
        }, 'Queue type removed.');
    }
}

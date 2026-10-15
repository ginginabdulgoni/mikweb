<?php

declare(strict_types=1);

namespace App\Http\Controllers\Router;

use App\Domain\Audit\AuditLogger;
use App\Domain\Router\Actions\SaveRouter;
use App\Domain\Router\Data\RouterPresenter;
use App\Domain\Router\Enums\RouterStatus;
use App\Domain\Router\Enums\Transport;
use App\Domain\Router\Models\Router;
use App\Http\Controllers\Controller;
use App\Http\Requests\Router\StoreRouterRequest;
use App\Http\Requests\Router\UpdateRouterRequest;
use App\Services\RouterOS\ResourceClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RouterController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Router::class);

        $user = $request->user();

        $routers = Router::query()
            ->where('organization_id', $user->organization_id)
            ->when($request->string('search')->toString(), function ($q, $search) {
                $q->where(fn ($qq) => $qq
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('host', 'like', "%{$search}%")
                    ->orWhere('identity', 'like', "%{$search}%"));
            })
            ->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
            ->with('groups')
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Routers/Index', [
            'routers' => $routers->through(fn (Router $r) => RouterPresenter::summary($r)),
            'filters' => [
                'search' => $request->string('search')->toString(),
                'status' => $request->string('status')->toString(),
            ],
            'statusOptions' => array_map(fn (RouterStatus $s) => ['value' => $s->value, 'label' => $s->label()], RouterStatus::cases()),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Router::class);

        return Inertia::render('Routers/Form', [
            'router' => null,
            'transports' => $this->transportOptions(),
        ]);
    }

    public function store(StoreRouterRequest $request, SaveRouter $saveRouter): RedirectResponse
    {
        $user = $request->user();
        $router = $saveRouter->create($user->organization_id, $user->id, $request->validated());

        AuditLogger::record('router.created', $router, new: ['name' => $router->name, 'host' => $router->host], routerId: $router->id);

        return redirect()
            ->route('routers.show', $router)
            ->with('success', "Router \"{$router->name}\" created. Run Test Connection to verify reachability.");
    }

    public function show(Request $request, Router $router, ResourceClient $client): Response
    {
        $this->authorize('view', $router);
        $router->load(['credentials', 'groups', 'capability']);

        $interfaces = [];
        if ($router->status === RouterStatus::Online) {
            try {
                $raw = $client->list($router, '/interface', userId: $request->user()->id);
                $interfaces = array_map(fn ($i) => [
                    'name' => (string) ($i['name'] ?? ''),
                    'type' => (string) ($i['type'] ?? ''),
                    'comment' => $i['comment'] ?? null,
                ], $raw);
            } catch (\Throwable) {
                // Non-critical: fail silently if device is unreachable
            }
        }

        return Inertia::render('Routers/Show', [
            'router' => RouterPresenter::detail($router),
            'interfaces' => $interfaces,
            'connectionLogs' => $router->connectionLogs()
                ->latest('created_at')->limit(10)->get()
                ->map(fn ($log) => [
                    'event' => $log->event,
                    'transport' => $log->transport,
                    'success' => $log->success,
                    'duration_ms' => $log->duration_ms,
                    'message' => $log->message,
                    'created_at' => $log->created_at?->toIso8601String(),
                ]),
            'can' => [
                'update' => $request->user()->can('update', $router),
                'delete' => $request->user()->can('delete', $router),
                'test' => $request->user()->can('test', $router),
            ],
        ]);
    }

    public function edit(Router $router): Response
    {
        $this->authorize('update', $router);
        $router->load('credentials');

        return Inertia::render('Routers/Form', [
            'router' => RouterPresenter::detail($router),
            'transports' => $this->transportOptions(),
        ]);
    }

    public function update(UpdateRouterRequest $request, Router $router, SaveRouter $saveRouter): RedirectResponse
    {
        $before = ['name' => $router->name, 'host' => $router->host];
        $router = $saveRouter->update($router, $request->validated());

        AuditLogger::record('router.updated', $router, old: $before, new: ['name' => $router->name, 'host' => $router->host], routerId: $router->id);

        return redirect()
            ->route('routers.show', $router)
            ->with('success', 'Router updated.');
    }

    public function destroy(Request $request, Router $router): RedirectResponse
    {
        $this->authorize('delete', $router);

        $name = $router->name;
        AuditLogger::record('router.deleted', $router, old: ['name' => $name, 'host' => $router->host], routerId: $router->id);
        $router->delete();

        return redirect()
            ->route('routers.index')
            ->with('success', "Router \"{$name}\" removed.");
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function transportOptions(): array
    {
        return [
            ['value' => Transport::Api->value, 'label' => 'RouterOS API (8728)'],
            ['value' => Transport::ApiSsl->value, 'label' => 'RouterOS API-SSL (8729)'],
            ['value' => Transport::Ssh->value, 'label' => 'SSH'],
            ['value' => Transport::Rest->value, 'label' => 'REST (RouterOS v7)'],
        ];
    }
}

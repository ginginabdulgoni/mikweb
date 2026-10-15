<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Backup\BackupService;
use App\Domain\Router\Actions\SyncRouterInfo;
use App\Domain\Router\Data\RouterPresenter;
use App\Domain\Router\Models\Router;
use App\Services\RouterOS\ConnectionManager;
use App\Support\Access\Permissions;
use App\Support\Access\RouterAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bulk operations across many routers, with a per-router result report.
 * Scoped to the user's organisation and gated by router.test / backup.manage.
 */
class BulkController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Router::class);

        $routers = Router::where('organization_id', $request->user()->organization_id)
            ->orderBy('name')->get()
            ->map(fn (Router $r) => RouterPresenter::summary($r));

        return Inertia::render('Bulk/Index', [
            'routers' => $routers,
            'results' => session('bulk_results'),
        ]);
    }

    public function test(Request $request, ConnectionManager $connections): RedirectResponse
    {
        $this->authorize('viewAny', Router::class);
        $routers = $this->selected($request);

        $results = [];
        foreach ($routers as $router) {
            if (! $request->user()->can('test', $router)) {
                continue;
            }
            $router->loadMissing('credentials');
            $res = $connections->testConnection($router);
            $results[] = [
                'router' => $router->name,
                'ok' => $res->success,
                'message' => $res->success ? ($res->info['version'] ?? 'connected') : $res->message,
            ];
        }

        return back()->with('bulk_results', ['operation' => 'Test connection', 'items' => $results]);
    }

    public function backup(Request $request, BackupService $service): RedirectResponse
    {
        $this->authorize('viewAny', Router::class);
        $routers = $this->selected($request);

        $results = [];
        foreach ($routers as $router) {
            if (! app(RouterAccess::class)->allows($request->user(), Permissions::BACKUP_MANAGE, $router)) {
                continue;
            }
            try {
                $backup = $service->export($router, $request->user()->id);
                $results[] = ['router' => $router->name, 'ok' => true, 'message' => $backup->filename];
            } catch (\Throwable $e) {
                $results[] = ['router' => $router->name, 'ok' => false, 'message' => $e->getMessage()];
            }
        }

        return back()->with('bulk_results', ['operation' => 'Backup export', 'items' => $results]);
    }

    public function reboot(Request $request, ConnectionManager $connections): RedirectResponse
    {
        $this->authorize('viewAny', Router::class);
        $routers = $this->selected($request);

        $results = [];
        foreach ($routers as $router) {
            if (! $request->user()->can('update', $router)) {
                continue;
            }
            try {
                $router->loadMissing('credentials');
                $connections->execute($router, '/system/reboot', [], $request->user()->id);
                $results[] = ['router' => $router->name, 'ok' => true, 'message' => 'Reboot signal sent.'];
            } catch (\Throwable $e) {
                $msg = strtolower($e->getMessage());
                if (str_contains($msg, 'reset') || str_contains($msg, 'closed') || str_contains($msg, 'pipe')) {
                    $results[] = ['router' => $router->name, 'ok' => true, 'message' => 'Reboot signal sent.'];
                } else {
                    $results[] = ['router' => $router->name, 'ok' => false, 'message' => $e->getMessage()];
                }
            }
        }

        return back()->with('bulk_results', ['operation' => 'Reboot devices', 'items' => $results]);
    }

    public function sync(Request $request, SyncRouterInfo $syncRouterInfo): RedirectResponse
    {
        $this->authorize('viewAny', Router::class);
        $routers = $this->selected($request);

        $results = [];
        foreach ($routers as $router) {
            if (! $request->user()->can('test', $router)) {
                continue;
            }
            try {
                $router->loadMissing('credentials');
                $syncRouterInfo->execute($router);
                $results[] = ['router' => $router->name, 'ok' => true, 'message' => "Refreshed ({$router->identity}, v{$router->routeros_version})"];
            } catch (\Throwable $e) {
                $results[] = ['router' => $router->name, 'ok' => false, 'message' => $e->getMessage()];
            }
        }

        return back()->with('bulk_results', ['operation' => 'Sync device info', 'items' => $results]);
    }

    public function command(Request $request, ConnectionManager $connections): RedirectResponse
    {
        $this->authorize('viewAny', Router::class);
        $data = $request->validate([
            'routers' => ['required', 'array'],
            'routers.*' => ['integer'],
            'command' => ['required', 'string', 'max:255'],
        ]);

        $routers = Router::whereIn('id', $data['routers'])
            ->where('organization_id', $request->user()->organization_id)
            ->get();

        $cmd = trim($data['command']);
        if (! str_starts_with($cmd, '/')) {
            $cmd = '/'.$cmd;
        }

        $results = [];
        foreach ($routers as $router) {
            if (! $request->user()->can('update', $router)) {
                continue;
            }
            try {
                $router->loadMissing('credentials');
                $out = $connections->execute($router, $cmd, [], $request->user()->id);
                $msg = $out === [] ? 'Executed successfully (OK)' : (json_encode($out[0] ?? []) ?: 'OK');
                $results[] = ['router' => $router->name, 'ok' => true, 'message' => mb_substr($msg, 0, 150)];
            } catch (\Throwable $e) {
                $results[] = ['router' => $router->name, 'ok' => false, 'message' => $e->getMessage()];
            }
        }

        return back()->with('bulk_results', ['operation' => "Command: {$cmd}", 'items' => $results]);
    }

    /** @return Collection<int, Router> */
    private function selected(Request $request)
    {
        $ids = (array) $request->validate(['routers' => ['required', 'array'], 'routers.*' => ['integer']])['routers'];

        return Router::whereIn('id', $ids)
            ->where('organization_id', $request->user()->organization_id)
            ->get();
    }
}

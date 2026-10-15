<?php

declare(strict_types=1);

namespace App\Http\Controllers\Router;

use App\Domain\Audit\AuditLogger;
use App\Domain\Router\Actions\SyncRouterInfo;
use App\Domain\Router\Models\Router;
use App\Http\Controllers\Controller;
use App\Services\RouterOS\ConnectionManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Real device actions: Test Connection and Sync Info. Both go through the
 * ConnectionManager, which actually opens a RouterOS session (or the mock in
 * dev), so these are genuine operations — not simulated UI feedback.
 */
class RouterConnectionController extends Controller
{
    public function test(Request $request, Router $router, ConnectionManager $connections): RedirectResponse
    {
        $this->authorize('test', $router);

        $router->load('credentials');
        $result = $connections->testConnection($router);

        AuditLogger::record(
            'router.test_connection',
            $router,
            new: ['success' => $result->success, 'duration_ms' => $result->durationMs],
            routerId: $router->id,
        );

        return back()->with(
            $result->success ? 'success' : 'error',
            $result->success
                ? "Connected in {$result->durationMs}ms — ".($result->info['identity'] ?? 'device').' ('.($result->info['version'] ?? 'unknown version').').'
                : "Test failed: {$result->message}",
        );
    }

    public function sync(Request $request, Router $router, SyncRouterInfo $syncRouterInfo): RedirectResponse
    {
        $this->authorize('test', $router);

        $router->load('credentials');

        try {
            $syncRouterInfo->execute($router);
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not read device info: '.$e->getMessage());
        }

        AuditLogger::record('router.sync_info', $router, routerId: $router->id);

        return back()->with('success', 'Device identity, version, and capabilities refreshed.');
    }
}

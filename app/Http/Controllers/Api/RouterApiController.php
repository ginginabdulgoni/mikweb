<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Router\Data\RouterPresenter;
use App\Domain\Router\Models\Router;
use App\Http\Controllers\Controller;
use App\Services\RouterOS\ConnectionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Token-authenticated (Sanctum) REST surface for routers. Mirrors the policy
 * checks of the web UI. This is intentionally read + test only for Phase 1;
 * configuration endpoints land with their respective modules.
 */
class RouterApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Router::class);

        $routers = Router::query()
            ->where('organization_id', $request->user()->organization_id)
            ->with('groups')
            ->orderBy('name')
            ->paginate((int) $request->integer('per_page', 25));

        return response()->json([
            'data' => $routers->through(fn (Router $r) => RouterPresenter::summary($r))->items(),
            'meta' => [
                'current_page' => $routers->currentPage(),
                'last_page' => $routers->lastPage(),
                'total' => $routers->total(),
            ],
        ]);
    }

    public function show(Request $request, Router $router): JsonResponse
    {
        $this->authorize('view', $router);
        $router->load(['credentials', 'groups', 'capability']);

        return response()->json(['data' => RouterPresenter::detail($router)]);
    }

    public function test(Request $request, Router $router, ConnectionManager $connections): JsonResponse
    {
        $this->authorize('test', $router);

        $router->load('credentials');
        $result = $connections->testConnection($router);

        return response()->json(['data' => $result->toArray()], $result->success ? 200 : 422);
    }
}

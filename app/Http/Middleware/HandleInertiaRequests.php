<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Monitoring\Models\RouterMetric;
use App\Domain\Router\Models\Router;
use App\Models\User;
use App\Support\Access\Permissions;
use App\Support\Access\RouterAccess;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_super_admin' => $user->isSuperAdmin(),
                    'organization' => $user->organization?->only(['id', 'name']),
                    'roles' => $user->getRoleNames()->values(),
                ] : null,
                // Coarse, org-wide abilities for UI gating. Per-router checks
                // still happen server-side on every request.
                'can' => $user ? $this->abilities($user) : [],
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            // One-time display of 2FA recovery codes after enrolment.
            'recoveryCodes' => fn () => $request->session()->get('recoveryCodes'),
            'app' => [
                'name' => config('app.name'),
            ],
            // WinBox-style "session": the selected router + the picker options.
            'nav' => $user ? $this->navigation($request, $user) : ['activeRouter' => null, 'routers' => []],
        ];
    }

    /**
     * Resolves the active router (the "session" you're connected to). If the
     * current page is a /routers/{router}/… page, that router becomes active
     * and is remembered in the session; otherwise the last remembered router
     * is used. Also returns the lightweight list for the navbar picker.
     *
     * @return array<string, mixed>
     */
    private function navigation(Request $request, User $user): array
    {
        $routeRouter = $request->route('router');
        $activeId = $routeRouter instanceof Router
            ? (int) $routeRouter->id
            : (int) ($request->session()->get('active_router_id') ?? 0);

        $active = null;
        if ($activeId > 0) {
            $router = Router::where('organization_id', $user->organization_id)->find($activeId);
            if ($router !== null) {
                $request->session()->put('active_router_id', $router->id);
                $metric = RouterMetric::where('router_id', $router->id)->latest('id')->first();
                $active = [
                    'id' => $router->id,
                    'name' => $router->name,
                    'host' => $router->host,
                    'status' => $router->status->value,
                    'status_color' => $router->status->color(),
                    'routeros_major' => $router->capability?->routeros_major,
                    'cpu_load' => $metric?->cpu_load,
                    'mem_percent' => $metric?->memoryPercent(),
                ];
            } else {
                $request->session()->forget('active_router_id');
            }
        }

        $routers = Router::where('organization_id', $user->organization_id)
            ->orderBy('name')
            ->get(['id', 'name', 'host', 'status'])
            ->map(fn (Router $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'host' => $r->host,
                'status_color' => $r->status->color(),
            ])->values();

        return ['activeRouter' => $active, 'routers' => $routers];
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(User $user): array
    {
        $access = app(RouterAccess::class);

        $abilities = [];
        foreach (Permissions::all() as $ability) {
            $abilities[$ability] = $access->allows($user, $ability);
        }

        return $abilities;
    }
}

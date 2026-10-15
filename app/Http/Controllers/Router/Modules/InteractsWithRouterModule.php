<?php

declare(strict_types=1);

namespace App\Http\Controllers\Router\Modules;

use App\Domain\Router\Models\Router;
use App\Services\RouterOS\Exceptions\RouterOsException;
use App\Support\Access\RouterAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Shared behaviour for per-router module controllers: scope + ability checks
 * and uniform RouterOS error handling (device errors become a flash message,
 * never a 500).
 */
trait InteractsWithRouterModule
{
    protected function ensureModuleAccess(Router $router, string $ability): void
    {
        // Router must be in the user's org (policy) AND the module ability granted.
        $this->authorize('view', $router);

        if (! app(RouterAccess::class)->allows(request()->user(), $ability, $router)) {
            abort(403, 'You do not have the required permission for this module.');
        }
    }

    /**
     * Run a device mutation, translating RouterOS failures into a redirect
     * back with a readable error instead of an exception page.
     *
     * @param  callable(): void  $callback
     */
    protected function deviceAction(callable $callback, string $success): RedirectResponse
    {
        try {
            $callback();
        } catch (RouterOsException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }

    /**
     * Read from the device for an Inertia page, surfacing unreachable devices
     * as empty data + a banner rather than a hard failure.
     *
     * @param  callable(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    protected function deviceRead(callable $callback): array
    {
        try {
            return ['data' => $callback(), 'error' => null];
        } catch (RouterOsException $e) {
            return ['data' => [], 'error' => $e->getMessage()];
        }
    }

    protected function requireId(string $id): string
    {
        if ($id === '' || ! str_starts_with($id, '*')) {
            throw ValidationException::withMessages(['id' => 'A valid RouterOS item id (.id) is required.']);
        }

        return $id;
    }
}

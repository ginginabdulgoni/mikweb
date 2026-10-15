<?php

declare(strict_types=1);

namespace App\Support\Access;

use App\Domain\Router\Models\Router;
use App\Models\User;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Centralises "can user U do ability A (optionally on router R)". Combines four
 * layers so authorization logic lives in exactly one place:
 *
 *   1. super-admin         → everything, every organization
 *   2. organization scope  → a user only ever sees their own org's routers
 *   3. role permissions    → spatie role/permission grants (org-wide)
 *   4. per-router grants    → user_router_permissions rows (router or group scoped)
 *
 * A user is allowed if their role grants the ability OR a per-router grant does.
 */
class RouterAccess
{
    public function allows(User $user, string $ability, ?Router $router = null): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($router !== null && ! $this->inScope($user, $router)) {
            return false;
        }

        if ($this->roleGrants($user, $ability)) {
            return true;
        }

        return $router !== null && $this->perRouterGrant($user, $ability, $router);
    }

    /** The router must belong to the user's organization. */
    public function inScope(User $user, Router $router): bool
    {
        return $user->organization_id !== null
            && (int) $router->organization_id === (int) $user->organization_id;
    }

    private function roleGrants(User $user, string $ability): bool
    {
        // spatie: permission names mirror our ability strings. hasPermissionTo
        // throws for an unregistered permission; treat that as "not granted".
        try {
            return $user->hasPermissionTo($ability, 'web');
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    private function perRouterGrant(User $user, string $ability, Router $router): bool
    {
        $groupIds = $router->groups->pluck('id')->all();

        return $user->routerPermissions()
            ->where(function ($query) use ($router, $groupIds) {
                $query->where('router_id', $router->id)
                    ->orWhereIn('router_group_id', $groupIds ?: [0])
                    ->orWhere(fn ($q) => $q->whereNull('router_id')->whereNull('router_group_id'));
            })
            ->get()
            ->contains(fn ($grant) => $grant->grants($ability));
    }
}

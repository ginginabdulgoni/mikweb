<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Router\Models\Router;
use App\Models\User;
use App\Support\Access\Permissions;
use App\Support\Access\RouterAccess;

class RouterPolicy
{
    public function __construct(private readonly RouterAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $this->access->allows($user, Permissions::ROUTER_VIEW);
    }

    public function view(User $user, Router $router): bool
    {
        return $this->access->allows($user, Permissions::ROUTER_VIEW, $router);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, Permissions::ROUTER_MANAGE);
    }

    public function update(User $user, Router $router): bool
    {
        return $this->access->allows($user, Permissions::ROUTER_MANAGE, $router);
    }

    public function delete(User $user, Router $router): bool
    {
        return $this->access->allows($user, Permissions::ROUTER_MANAGE, $router);
    }

    public function test(User $user, Router $router): bool
    {
        return $this->access->allows($user, Permissions::ROUTER_TEST, $router);
    }

    public function manageCredentials(User $user, Router $router): bool
    {
        return $this->access->allows($user, Permissions::ROUTER_CREDENTIALS, $router);
    }
}

<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Router\Models\Router;
use App\Models\User;
use App\Policies\RouterPolicy;
use App\Support\Access\Permissions;
use App\Support\Access\RouterAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RouterAccess::class);
    }

    public function boot(): void
    {
        // Router lives under App\Domain, so its policy must be mapped explicitly.
        Gate::policy(Router::class, RouterPolicy::class);

        // Super admins bypass every check.
        Gate::before(function (User $user) {
            return $user->isSuperAdmin() ? true : null;
        });

        // Ability-only gates (not tied to a model instance).
        foreach ([Permissions::USER_MANAGE, Permissions::ORG_MANAGE, Permissions::AUDIT_VIEW, Permissions::MONITORING_VIEW, Permissions::GROUP_MANAGE, Permissions::GROUP_VIEW] as $ability) {
            Gate::define($ability, function (User $user) use ($ability): bool {
                return app(RouterAccess::class)->allows($user, $ability);
            });
        }
    }
}

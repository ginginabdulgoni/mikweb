<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Access\Permissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('permission:cache-reset');

        foreach (Permissions::all() as $ability) {
            Permission::findOrCreate($ability, 'web');
        }

        // super-admin exists for completeness but is bypassed in Gate::before.
        Role::findOrCreate(Permissions::ROLE_SUPER_ADMIN, 'web');

        foreach (Permissions::roleMatrix() as $roleName => $abilities) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($abilities);
        }

        Artisan::call('permission:cache-reset');
    }
}

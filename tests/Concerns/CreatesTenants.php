<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Helpers for building an organisation + users with roles in feature tests.
 */
trait CreatesTenants
{
    protected function seedRoles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function makeUser(?Organization $org = null, ?string $role = null, bool $superAdmin = false): User
    {
        $org ??= Organization::factory()->create();

        $user = User::factory()->create([
            'organization_id' => $org->id,
            'is_super_admin' => $superAdmin,
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }
}

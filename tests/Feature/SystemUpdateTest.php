<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\Access\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class SystemUpdateTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_guest_cannot_access_system_update(): void
    {
        $this->get(route('system.update.index'))->assertRedirect(route('login'));
    }

    public function test_non_admin_user_is_forbidden_from_system_update(): void
    {
        $this->seedRoles();
        $user = User::factory()->create();
        $user->assignRole(Permissions::ROLE_READ_ONLY);

        $this->actingAs($user)
            ->get(route('system.update.index'))
            ->assertForbidden();
    }

    public function test_super_admin_can_view_system_update_and_git_history(): void
    {
        $this->seedRoles();
        $admin = User::factory()->create(['is_super_admin' => true]);
        $admin->assignRole(Permissions::ROLE_SUPER_ADMIN);

        $response = $this->actingAs($admin)->get(route('system.update.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('System/Update')
            ->has('git')
            ->has('commits')
            ->has('system')
        );
    }
}

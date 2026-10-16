<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Organization\Models\Organization;
use App\Domain\Router\Models\Router;
use App\Models\User;
use App\Support\Access\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NetworkMapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function makeUser(string $role): User
    {
        $org = Organization::firstOrCreate(
            ['slug' => 'test-org'],
            ['name' => 'Test Org', 'description' => 'Test']
        );

        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->syncRoles([$role]);

        return $user;
    }

    public function test_network_map_renders_with_topology(): void
    {
        $user = $this->makeUser(Permissions::ROLE_NETWORK_ADMIN);
        Router::factory()->withApiCredential()->create([
            'organization_id' => $user->organization_id,
            'name' => 'Core-Gateway',
        ]);

        $res = $this->actingAs($user)->get(route('network.map'));

        $res->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('NetworkMap/Index')
                ->has('nodes')
                ->has('links')
                ->where('summary.total', 1)
            );
    }
}

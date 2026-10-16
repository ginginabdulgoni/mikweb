<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Organization\Models\Organization;
use App\Domain\Router\Models\Router;
use App\Models\User;
use App\Support\Access\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityAuditTest extends TestCase
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

    public function test_security_audit_renders_for_router(): void
    {
        $user = $this->makeUser(Permissions::ROLE_NETWORK_ADMIN);
        $router = Router::factory()->withApiCredential()->create([
            'organization_id' => $user->organization_id,
            'name' => 'Audited-Router',
        ]);

        $res = $this->actingAs($user)->get(route('routers.security.audit', $router->id));

        $res->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Routers/Modules/SecurityAudit')
                ->has('score')
                ->has('grade')
                ->has('findings')
            );
    }

    public function test_hotspot_voucher_print_page_renders(): void
    {
        $user = $this->makeUser(Permissions::ROLE_NETWORK_OPERATOR);
        $router = Router::factory()->withApiCredential()->create([
            'organization_id' => $user->organization_id,
            'name' => 'Hotspot-Router',
        ]);

        $res = $this->actingAs($user)->get(route('routers.hotspot.vouchers.print', $router->id));

        $res->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Routers/Modules/HotspotPrint')
                ->has('router')
                ->has('users')
            );
    }
}

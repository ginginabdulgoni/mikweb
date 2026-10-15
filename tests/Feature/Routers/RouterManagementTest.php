<?php

declare(strict_types=1);

namespace Tests\Feature\Routers;

use App\Domain\Organization\Models\Organization;
use App\Domain\Router\Enums\CredentialType;
use App\Domain\Router\Enums\RouterStatus;
use App\Domain\Router\Models\Router;
use App\Services\RouterOS\Adapters\MockRouterOsClient;
use App\Support\Access\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class RouterManagementTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        MockRouterOsClient::reset();
        config(['mikweb.routeros.driver' => 'mock']);
    }

    public function test_network_admin_can_create_router_with_encrypted_credentials(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);

        $response = $this->actingAs($admin)->post('/routers', [
            'name' => 'Core Router',
            'host' => '192.0.2.10',
            'preferred_transport' => 'api',
            'credential' => ['username' => 'mikweb', 'password' => 'super-secret'],
        ]);

        $router = Router::where('host', '192.0.2.10')->firstOrFail();
        $response->assertRedirect(route('routers.show', $router));

        $this->assertSame($admin->organization_id, $router->organization_id);

        // Secret decrypts via the model but is stored encrypted at rest.
        $credential = $router->credentialFor(CredentialType::Api);
        $this->assertSame('super-secret', $credential->secret);
        $raw = \DB::table('router_credentials')->where('id', $credential->id)->value('secret');
        $this->assertNotSame('super-secret', $raw);

        $this->assertDatabaseHas('audit_logs', ['action' => 'router.created', 'router_id' => $router->id]);
    }

    public function test_operator_cannot_create_router(): void
    {
        $operator = $this->makeUser(role: Permissions::ROLE_NETWORK_OPERATOR);

        $this->actingAs($operator)
            ->post('/routers', ['name' => 'X', 'host' => '192.0.2.11', 'preferred_transport' => 'api'])
            ->assertForbidden();

        $this->assertDatabaseMissing('routers', ['host' => '192.0.2.11']);
    }

    public function test_user_cannot_view_router_from_another_organization(): void
    {
        $mine = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $otherOrg = Organization::factory()->create();
        $foreign = Router::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($mine)->get(route('routers.show', $foreign))->assertForbidden();
    }

    public function test_index_only_lists_own_organization_routers(): void
    {
        $user = $this->makeUser(role: Permissions::ROLE_READ_ONLY);
        Router::factory()->create(['organization_id' => $user->organization_id, 'name' => 'Mine']);
        Router::factory()->create(['name' => 'Theirs']); // different org via factory

        $this->actingAs($user)
            ->get('/routers')
            ->assertInertia(fn ($page) => $page
                ->component('Routers/Index')
                ->has('routers.data', 1));
    }

    public function test_operator_can_test_connection_and_it_updates_status(): void
    {
        $operator = $this->makeUser(role: Permissions::ROLE_NETWORK_OPERATOR);
        $router = Router::factory()->withApiCredential()->create(['organization_id' => $operator->organization_id]);

        $this->actingAs($operator)
            ->post(route('routers.test', $router))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(RouterStatus::Online, $router->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'router.test_connection', 'router_id' => $router->id]);
    }

    public function test_read_only_user_cannot_test_connection(): void
    {
        $user = $this->makeUser(role: Permissions::ROLE_READ_ONLY);
        $router = Router::factory()->withApiCredential()->create(['organization_id' => $user->organization_id]);

        $this->actingAs($user)->post(route('routers.test', $router))->assertForbidden();
    }

    public function test_sync_info_detects_capabilities(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = Router::factory()->withApiCredential()->create(['organization_id' => $admin->organization_id]);

        $this->actingAs($admin)->post(route('routers.sync', $router))->assertSessionHas('success');

        $router->refresh()->load('capability');
        $this->assertNotNull($router->capability);
        $this->assertSame(7, $router->capability->routeros_major);
        $this->assertTrue($router->capability->supports_rest);
    }

    public function test_api_returns_routers_for_token_with_scope(): void
    {
        $user = $this->makeUser(role: Permissions::ROLE_READ_ONLY);
        Router::factory()->create(['organization_id' => $user->organization_id]);

        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/routers')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'host', 'status']], 'meta']);
    }
}

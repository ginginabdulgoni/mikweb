<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Organization\Models\Organization;
use App\Domain\Router\Models\Router;
use App\Models\User;
use App\Support\Access\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiRouterMonitoringTest extends TestCase
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

    public function test_monitoring_supports_multi_router_selection_and_traffic_tab(): void
    {
        $admin = $this->makeUser(Permissions::ROLE_NETWORK_ADMIN);
        $router1 = Router::factory()->withApiCredential()->create(['organization_id' => $admin->organization_id, 'name' => 'Core-1']);
        $router2 = Router::factory()->withApiCredential()->create(['organization_id' => $admin->organization_id, 'name' => 'Edge-2']);

        $response = $this->actingAs($admin)->get(route('monitoring.index', [
            'routers' => "{$router1->id},{$router2->id}",
            'tab' => 'traffic',
        ]));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Monitoring/Index')
                ->where('initialTab', 'traffic')
                ->has('routers', 2)
                ->where('selectedRouters', [$router1->id, $router2->id])
                ->has('routers.0.hardware_model')
                ->has('kpis')
                ->has('chart')
            );
    }

    public function test_unauthenticated_user_cannot_access_monitoring(): void
    {
        $this->get(route('monitoring.index'))->assertRedirect(route('login'));
    }

    public function test_read_only_user_without_monitoring_permission_is_forbidden(): void
    {
        $user = $this->makeUser(Permissions::ROLE_READ_ONLY);
        $this->actingAs($user)->get(route('monitoring.index'))->assertForbidden();
    }

    public function test_router_interfaces_traffic_endpoint_returns_json(): void
    {
        $admin = $this->makeUser(Permissions::ROLE_NETWORK_ADMIN);
        $router = Router::factory()->withApiCredential()->create(['organization_id' => $admin->organization_id]);

        $response = $this->actingAs($admin)->get(route('routers.interfaces.traffic', [
            'router' => $router->id,
            'interface' => 'ether1',
        ]));

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'interface',
                'rx_bps',
                'tx_bps',
                'rx_packets',
                'tx_packets',
                'timestamp',
            ]);
    }

    public function test_fleet_stats_endpoint_returns_live_resources_and_pppoe_hotspot_counts(): void
    {
        $admin = $this->makeUser(Permissions::ROLE_NETWORK_ADMIN);
        $router1 = Router::factory()->withApiCredential()->create(['organization_id' => $admin->organization_id, 'name' => 'Core-1']);
        $router2 = Router::factory()->withApiCredential()->create(['organization_id' => $admin->organization_id, 'name' => 'Home-2']);

        $response = $this->actingAs($admin)->get(route('monitoring.fleet-stats', [
            'routers' => "{$router1->id},{$router2->id}",
        ]));

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'routers' => [
                    (string) $router1->id => [
                        'id',
                        'status',
                        'uptime',
                        'cpu_load',
                        'mem_percent',
                        'ppp' => ['active', 'secrets'],
                        'hotspot' => ['active', 'users'],
                    ],
                    (string) $router2->id => [
                        'id',
                        'status',
                        'uptime',
                        'cpu_load',
                        'mem_percent',
                        'ppp' => ['active', 'secrets'],
                        'hotspot' => ['active', 'users'],
                    ],
                ],
                'timestamp',
            ]);

        $this->assertTrue($response->json('success'));
        $this->assertNotNull($response->json("routers.{$router1->id}.uptime"));
        $this->assertNotNull($response->json("routers.{$router2->id}.uptime"));
    }

    public function test_unauthenticated_cannot_access_fleet_stats(): void
    {
        $this->get(route('monitoring.fleet-stats'))->assertRedirect(route('login'));
    }
}

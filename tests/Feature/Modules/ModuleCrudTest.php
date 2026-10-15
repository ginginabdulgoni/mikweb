<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Domain\Router\Models\Router;
use App\Services\RouterOS\Adapters\MockRouterOsClient;
use App\Services\RouterOS\ResourceClient;
use App\Support\Access\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ModuleCrudTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        MockRouterOsClient::reset();
        config(['mikweb.routeros.driver' => 'mock']);
    }

    private function router(int $orgId): Router
    {
        return Router::factory()->withApiCredential()->create(['organization_id' => $orgId]);
    }

    public function test_interfaces_page_lists_device_interfaces(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);

        $this->actingAs($admin)
            ->get(route('routers.interfaces.index', $router))
            ->assertInertia(fn ($p) => $p->component('Routers/Modules/Interfaces')->has('interfaces', 3));
    }

    public function test_ip_address_crud_against_device(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);

        // Create
        $this->actingAs($admin)->post(route('routers.ip.addresses.store', $router), [
            'address' => '10.5.5.1/24', 'interface' => 'ether1',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/ip/address');
        $this->assertContains('10.5.5.1/24', array_column($rows, 'address'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'ip-address.add', 'router_id' => $router->id]);
    }

    public function test_read_only_user_cannot_manage_ip_addresses(): void
    {
        $user = $this->makeUser(role: Permissions::ROLE_READ_ONLY);
        $router = $this->router($user->organization_id);

        // read-only has ip.view (can see) ...
        $this->actingAs($user)->get(route('routers.ip.addresses.index', $router))->assertOk();
        // ... but not ip.manage
        $this->actingAs($user)->post(route('routers.ip.addresses.store', $router), [
            'address' => '10.5.5.9/24', 'interface' => 'ether1',
        ])->assertForbidden();
    }

    public function test_operator_can_manage_ppp_secrets(): void
    {
        $operator = $this->makeUser(role: Permissions::ROLE_NETWORK_OPERATOR);
        $router = $this->router($operator->organization_id);

        $this->actingAs($operator)->post(route('routers.ppp.secrets.store', $router), [
            'name' => 'newcustomer', 'password' => 'secret123', 'service' => 'pppoe',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/ppp/secret');
        $this->assertContains('newcustomer', array_column($rows, 'name'));
    }

    public function test_operator_can_manage_ppp_profiles(): void
    {
        $operator = $this->makeUser(role: Permissions::ROLE_NETWORK_OPERATOR);
        $router = $this->router($operator->organization_id);

        $this->actingAs($operator)->post(route('routers.ppp.profiles.store', $router), [
            'name' => 'profile-50mbps',
            'local_address' => '10.0.0.1',
            'remote_address' => 'pool-dhcp',
            'rate_limit' => '50M/50M',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/ppp/profile');
        $this->assertContains('profile-50mbps', array_column($rows, 'name'));
    }

    public function test_operator_can_view_ppp_page_and_pools_are_resolved(): void
    {
        $operator = $this->makeUser(role: Permissions::ROLE_NETWORK_OPERATOR);
        $router = $this->router($operator->organization_id);

        $response = $this->actingAs($operator)
            ->get(route('routers.ppp.index', $router))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Routers/Modules/Ppp')
                ->has('pools')
                ->has('profiles')
                ->has('secrets')
            );

        // Verify that the profile whose raw remote-address was '*1' gets resolved to the pool name 'dhcp-pool-1'
        $profiles = $response->viewData('page')['props']['profiles'];
        $defaultProfile = collect($profiles)->firstWhere('name', 'default');
        $this->assertNotNull($defaultProfile);
        $this->assertSame('dhcp-pool-1', $defaultProfile['remote-address']);
    }

    public function test_operator_can_manage_pppoe_servers(): void
    {
        $operator = $this->makeUser(role: Permissions::ROLE_NETWORK_OPERATOR);
        $router = $this->router($operator->organization_id);

        $this->actingAs($operator)->post(route('routers.ppp.servers.store', $router), [
            'service_name' => 'pppoe-service-test',
            'interface' => 'ether1',
            'default_profile' => 'default',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/interface/pppoe-server/server');
        $this->assertContains('pppoe-service-test', array_column($rows, 'service-name'));
    }

    public function test_operator_can_batch_manage_ppp_secrets(): void
    {
        $operator = $this->makeUser(role: Permissions::ROLE_NETWORK_OPERATOR);
        $router = $this->router($operator->organization_id);

        $this->actingAs($operator)->post(route('routers.ppp.secrets.store', $router), [
            'name' => 'batch-user-1',
            'password' => 'secret123',
            'service' => 'pppoe',
            'profile' => 'default',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/ppp/secret');
        $target = collect($rows)->firstWhere('name', 'batch-user-1');
        $this->assertNotNull($target);

        // Batch disable
        $this->actingAs($operator)->post(route('routers.ppp.secrets.batch', $router), [
            'action' => 'disable',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/ppp/secret');
        $updated = collect($rows)->firstWhere('name', 'batch-user-1');
        $this->assertSame('true', $updated['disabled']);

        // Batch change profile
        $this->actingAs($operator)->post(route('routers.ppp.secrets.batch', $router), [
            'action' => 'change_profile',
            'ids' => [$target['.id']],
            'target_profile' => 'default-encryption',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/ppp/secret');
        $updated = collect($rows)->firstWhere('name', 'batch-user-1');
        $this->assertSame('default-encryption', $updated['profile']);
    }

    public function test_operator_can_batch_generate_ppp_secrets(): void
    {
        $operator = $this->makeUser(role: Permissions::ROLE_NETWORK_OPERATOR);
        $router = $this->router($operator->organization_id);

        $this->actingAs($operator)->post(route('routers.ppp.secrets.generate', $router), [
            'count' => 3,
            'prefix' => 'batchtest-',
            'user_format' => 'sequential',
            'start_index' => 1,
            'password_mode' => 'numeric',
            'service' => 'pppoe',
            'profile' => 'default',
            'comment' => 'Batch Test Comment',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/ppp/secret');
        $names = array_column($rows, 'name');
        $this->assertContains('batchtest-001', $names);
        $this->assertContains('batchtest-002', $names);
        $this->assertContains('batchtest-003', $names);
    }

    public function test_operator_can_update_ppp_secret_password(): void
    {
        $operator = $this->makeUser(role: Permissions::ROLE_NETWORK_OPERATOR);
        $router = $this->router($operator->organization_id);

        $this->actingAs($operator)->post(route('routers.ppp.secrets.store', $router), [
            'name' => 'pass-user-1',
            'password' => 'initialpass123',
            'service' => 'pppoe',
            'profile' => 'default',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/ppp/secret');
        $target = collect($rows)->firstWhere('name', 'pass-user-1');
        $this->assertNotNull($target);

        $this->actingAs($operator)->put(route('routers.ppp.secrets.password', $router), [
            'id' => $target['.id'],
            'password' => 'supersecretpass999',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/ppp/secret');
        $updated = collect($rows)->firstWhere('name', 'pass-user-1');
        $this->assertSame('supersecretpass999', $updated['password']);
    }

    public function test_operator_can_update_ppp_aaa_settings(): void
    {
        $operator = $this->makeUser(role: Permissions::ROLE_NETWORK_OPERATOR);
        $router = $this->router($operator->organization_id);

        $this->actingAs($operator)->put(route('routers.ppp.aaa.update', $router), [
            'use_radius' => 'yes',
            'accounting' => 'yes',
            'interim_update' => '5m',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/ppp/aaa');
        $this->assertSame('yes', $rows[0]['use-radius']);
        $this->assertSame('5m', $rows[0]['interim-update']);
    }

    public function test_operator_can_disconnect_all_active_ppp_sessions(): void
    {
        $operator = $this->makeUser(role: Permissions::ROLE_NETWORK_OPERATOR);
        $router = $this->router($operator->organization_id);

        $this->actingAs($operator)->post(route('routers.ppp.active.disconnect-all', $router))
            ->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/ppp/active');
        $this->assertEmpty($rows);
    }

    public function test_operator_can_manage_hotspot_users(): void
    {
        $operator = $this->makeUser(role: Permissions::ROLE_NETWORK_OPERATOR);
        $router = $this->router($operator->organization_id);

        $this->actingAs($operator)->post(route('routers.hotspot.users.store', $router), [
            'name' => 'voucher-99',
            'password' => 'pass123',
            'profile' => 'default',
            'server' => 'hotspot1',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/ip/hotspot/user');
        $this->assertContains('voucher-99', array_column($rows, 'name'));
    }

    public function test_operator_can_manage_hotspot_user_profiles(): void
    {
        $operator = $this->makeUser(role: Permissions::ROLE_NETWORK_OPERATOR);
        $router = $this->router($operator->organization_id);

        $this->actingAs($operator)->post(route('routers.hotspot.user-profiles.store', $router), [
            'name' => 'paket-speedy',
            'rate_limit' => '10M/10M',
            'shared_users' => '2',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/ip/hotspot/user/profile');
        $this->assertContains('paket-speedy', array_column($rows, 'name'));
    }

    public function test_operator_can_manage_hotspot_ip_bindings(): void
    {
        $operator = $this->makeUser(role: Permissions::ROLE_NETWORK_OPERATOR);
        $router = $this->router($operator->organization_id);

        $this->actingAs($operator)->post(route('routers.hotspot.ip-bindings.store', $router), [
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'type' => 'bypassed',
            'comment' => 'Printer Kasir',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/ip/hotspot/ip-binding');
        $this->assertContains('AA:BB:CC:DD:EE:FF', array_column($rows, 'mac-address'));
    }

    public function test_operator_can_view_hotspot_page(): void
    {
        $operator = $this->makeUser(role: Permissions::ROLE_NETWORK_OPERATOR);
        $router = $this->router($operator->organization_id);

        $this->actingAs($operator)
            ->get(route('routers.hotspot.index', $router))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Routers/Modules/Hotspot')->has('users')->has('active')->has('userProfiles')->has('serverProfiles'));
    }

    public function test_operator_can_batch_manage_hotspot_users(): void
    {
        $operator = $this->makeUser(role: Permissions::ROLE_NETWORK_OPERATOR);
        $router = $this->router($operator->organization_id);

        $this->actingAs($operator)->post(route('routers.hotspot.users.store', $router), [
            'name' => 'hs-batch-1',
            'password' => 'secret123',
            'profile' => 'default',
            'server' => 'hotspot1',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/ip/hotspot/user');
        $target = collect($rows)->firstWhere('name', 'hs-batch-1');
        $this->assertNotNull($target);

        // Batch disable
        $this->actingAs($operator)->post(route('routers.hotspot.users.batch', $router), [
            'action' => 'disable',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/ip/hotspot/user');
        $updated = collect($rows)->firstWhere('name', 'hs-batch-1');
        $this->assertSame('true', $updated['disabled']);

        // Batch change profile
        $this->actingAs($operator)->post(route('routers.hotspot.users.batch', $router), [
            'action' => 'change_profile',
            'ids' => [$target['.id']],
            'target_profile' => 'paket-1jam',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/ip/hotspot/user');
        $updated2 = collect($rows)->firstWhere('name', 'hs-batch-1');
        $this->assertSame('paket-1jam', $updated2['profile']);
    }

    public function test_operator_can_update_hotspot_user_password(): void
    {
        $operator = $this->makeUser(role: Permissions::ROLE_NETWORK_OPERATOR);
        $router = $this->router($operator->organization_id);

        $this->actingAs($operator)->post(route('routers.hotspot.users.store', $router), [
            'name' => 'hs-pwd-test',
            'password' => 'oldpassword',
            'profile' => 'default',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/ip/hotspot/user');
        $target = collect($rows)->firstWhere('name', 'hs-pwd-test');
        $this->assertNotNull($target);

        $this->actingAs($operator)->put(route('routers.hotspot.users.password', $router), [
            'id' => $target['.id'],
            'password' => 'newpassword999',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/ip/hotspot/user');
        $updated = collect($rows)->firstWhere('name', 'hs-pwd-test');
        $this->assertSame('newpassword999', $updated['password']);
    }

    public function test_operator_can_disconnect_all_active_hotspot_sessions(): void
    {
        $operator = $this->makeUser(role: Permissions::ROLE_NETWORK_OPERATOR);
        $router = $this->router($operator->organization_id);

        $this->actingAs($operator)->post(route('routers.hotspot.active.disconnect-all', $router))
            ->assertSessionHas('success');
    }

    public function test_operator_can_quick_bypass_hotspot_device(): void
    {
        $operator = $this->makeUser(role: Permissions::ROLE_NETWORK_OPERATOR);
        $router = $this->router($operator->organization_id);

        $this->actingAs($operator)->post(route('routers.hotspot.ip-bindings.quick-bypass', $router), [
            'mac_address' => '11:22:33:44:55:66',
            'address' => '192.168.88.77',
            'server' => 'hotspot1',
            'comment' => 'Bypass Smart TV POS',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/ip/hotspot/ip-binding');
        $target = collect($rows)->firstWhere('mac-address', '11:22:33:44:55:66');
        $this->assertNotNull($target);
        $this->assertSame('bypassed', $target['type']);
        $this->assertSame('192.168.88.77', $target['address']);
    }

    public function test_operator_can_manage_hotspot_server_profiles(): void
    {
        $operator = $this->makeUser(role: Permissions::ROLE_NETWORK_OPERATOR);
        $router = $this->router($operator->organization_id);

        $this->actingAs($operator)->post(route('routers.hotspot.server-profiles.store', $router), [
            'name' => 'sp-premium',
            'hotspot_address' => '192.168.88.1',
            'dns_name' => 'premium.login',
            'html_directory' => 'hotspot',
            'login_by' => 'http-chap,cookie',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/ip/hotspot/profile');
        $target = collect($rows)->firstWhere('name', 'sp-premium');
        $this->assertNotNull($target);
        $this->assertSame('premium.login', $target['dns-name']);
    }

    public function test_operator_can_destroy_hotspot_host(): void
    {
        $operator = $this->makeUser(role: Permissions::ROLE_NETWORK_OPERATOR);
        $router = $this->router($operator->organization_id);

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/ip/hotspot/host');
        $this->assertNotEmpty($rows);
        $hostId = $rows[0]['.id'];

        $this->actingAs($operator)->delete(route('routers.hotspot.hosts.destroy', $router), [
            'id' => $hostId,
        ])->assertSessionHas('success');

        $rowsAfter = app(ResourceClient::class)->list($router->fresh('credentials'), '/ip/hotspot/host');
        $this->assertNull(collect($rowsAfter)->firstWhere('.id', $hostId));
    }

    public function test_module_access_denied_cross_organization(): void
    {
        $user = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $foreign = Router::factory()->withApiCredential()->create(); // other org

        $this->actingAs($user)->get(route('routers.ppp.index', $foreign))->assertForbidden();
    }

    public function test_tools_ping_returns_rows(): void
    {
        $operator = $this->makeUser(role: Permissions::ROLE_NETWORK_OPERATOR);
        $router = $this->router($operator->organization_id);

        $this->actingAs($operator)
            ->postJson(route('routers.tools.ping', $router), ['address' => '8.8.8.8', 'count' => 3])
            ->assertOk()
            ->assertJsonStructure(['rows' => [['seq', 'host', 'time']]]);
    }

    public function test_firewall_rules_preserve_device_order(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);

        $this->actingAs($admin)
            ->get(route('routers.firewall.index', $router))
            ->assertInertia(fn ($p) => $p->component('Routers/Modules/Firewall')->has('rules', 2)->has('natRules'));
    }

    public function test_queues_page_and_crud(): void
    {
        $operator = $this->makeUser(role: Permissions::ROLE_NETWORK_OPERATOR);
        $router = $this->router($operator->organization_id);

        $this->actingAs($operator)
            ->get(route('routers.queues.index', $router))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Routers/Modules/Queues')->has('simpleQueues')->has('queueTree')->has('queueTypes'));

        $this->actingAs($operator)->post(route('routers.queues.simple.store', $router), [
            'name' => 'client-vip',
            'target' => '10.0.0.100/32',
            'max_limit' => '20M/20M',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/queue/simple');
        $this->assertContains('client-vip', array_column($rows, 'name'));
    }

    public function test_operator_can_batch_manage_simple_queues(): void
    {
        $operator = $this->makeUser(role: Permissions::ROLE_NETWORK_OPERATOR);
        $router = $this->router($operator->organization_id);

        $this->actingAs($operator)->post(route('routers.queues.simple.store', $router), [
            'name' => 'queue-batch-1',
            'target' => '10.0.0.101/32',
            'max_limit' => '10M/10M',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/queue/simple');
        $target = collect($rows)->firstWhere('name', 'queue-batch-1');
        $this->assertNotNull($target);

        // Batch disable
        $this->actingAs($operator)->post(route('routers.queues.simple.batch', $router), [
            'action' => 'disable',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/queue/simple');
        $updated = collect($rows)->firstWhere('name', 'queue-batch-1');
        $this->assertSame('true', $updated['disabled']);

        // Batch change limit
        $this->actingAs($operator)->post(route('routers.queues.simple.batch', $router), [
            'action' => 'change_limit',
            'ids' => [$target['.id']],
            'max_limit' => '25M/25M',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/queue/simple');
        $updated2 = collect($rows)->firstWhere('name', 'queue-batch-1');
        $this->assertSame('25M/25M', $updated2['max-limit']);

        // Batch reset counters
        $this->actingAs($operator)->post(route('routers.queues.simple.batch', $router), [
            'action' => 'reset_counters',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/queue/simple');
        $updated3 = collect($rows)->firstWhere('name', 'queue-batch-1');
        $this->assertSame('0/0', $updated3['bytes']);

        // Batch destroy
        $this->actingAs($operator)->post(route('routers.queues.simple.batch', $router), [
            'action' => 'destroy',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/queue/simple');
        $this->assertNull(collect($rows)->firstWhere('name', 'queue-batch-1'));
    }

    public function test_operator_can_quick_limit_simple_queue(): void
    {
        $operator = $this->makeUser(role: Permissions::ROLE_NETWORK_OPERATOR);
        $router = $this->router($operator->organization_id);

        $this->actingAs($operator)->post(route('routers.queues.simple.store', $router), [
            'name' => 'queue-quick-test',
            'target' => '10.0.0.102/32',
            'max_limit' => '5M/5M',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/queue/simple');
        $target = collect($rows)->firstWhere('name', 'queue-quick-test');
        $this->assertNotNull($target);

        $this->actingAs($operator)->put(route('routers.queues.simple.quick-limit', $router), [
            'id' => $target['.id'],
            'max_limit' => '100M/100M',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/queue/simple');
        $updated = collect($rows)->firstWhere('name', 'queue-quick-test');
        $this->assertSame('100M/100M', $updated['max-limit']);
    }

    public function test_operator_can_reset_counters_simple_queue(): void
    {
        $operator = $this->makeUser(role: Permissions::ROLE_NETWORK_OPERATOR);
        $router = $this->router($operator->organization_id);

        $this->actingAs($operator)->post(route('routers.queues.simple.reset-counters', $router))
            ->assertSessionHas('success');
    }

    public function test_operator_can_manage_queue_tree(): void
    {
        $operator = $this->makeUser(role: Permissions::ROLE_NETWORK_OPERATOR);
        $router = $this->router($operator->organization_id);

        $this->actingAs($operator)->post(route('routers.queues.tree.store', $router), [
            'name' => 'tree-video-stream',
            'parent' => 'global',
            'max_limit' => '50M',
            'limit_at' => '20M',
            'priority' => '5',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/queue/tree');
        $target = collect($rows)->firstWhere('name', 'tree-video-stream');
        $this->assertNotNull($target);

        // Toggle
        $this->actingAs($operator)->post(route('routers.queues.tree.toggle', $router), [
            'id' => $target['.id'],
            'disable' => true,
        ])->assertSessionHas('success');

        // Update
        $this->actingAs($operator)->put(route('routers.queues.tree.update', $router), [
            'id' => $target['.id'],
            'name' => 'tree-video-stream-updated',
            'parent' => 'global',
            'max_limit' => '80M',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/queue/tree');
        $updated = collect($rows)->firstWhere('name', 'tree-video-stream-updated');
        $this->assertNotNull($updated);
        $this->assertSame('80M', $updated['max-limit']);

        // Destroy
        $this->actingAs($operator)->delete(route('routers.queues.tree.destroy', $router), [
            'id' => $target['.id'],
        ])->assertSessionHas('success');

        $rowsAfter = app(ResourceClient::class)->list($router->fresh('credentials'), '/queue/tree');
        $this->assertNull(collect($rowsAfter)->firstWhere('name', 'tree-video-stream-updated'));
    }

    public function test_dhcp_page_and_crud(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);

        $this->actingAs($admin)
            ->get(route('routers.ip.dhcp.index', $router))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Routers/Modules/Dhcp')->has('leases')->has('servers')->has('networks')->has('pools'));

        $this->actingAs($admin)->post(route('routers.ip.dhcp.leases.store', $router), [
            'address' => '192.168.88.25',
            'mac_address' => '00:AA:BB:CC:DD:EE',
            'server' => 'dhcp1',
            'comment' => 'Server Room NAS',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/ip/dhcp-server/lease');
        $this->assertContains('192.168.88.25', array_column($rows, 'address'));

        // Make static
        $this->actingAs($admin)->post(route('routers.ip.dhcp.leases.make-static', $router), [
            'id' => '*2',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/ip/dhcp-server/lease');
        $item = collect($rows)->first(fn ($r) => ($r['.id'] ?? null) === '*2');
        $this->assertEquals('false', $item['dynamic']);
    }

    public function test_arp_page_and_crud(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);

        $this->actingAs($admin)
            ->get(route('routers.ip.arp.index', $router))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Routers/Modules/Arp')->has('entries'));

        $this->actingAs($admin)->post(route('routers.ip.arp.store', $router), [
            'address' => '192.168.88.99',
            'mac_address' => '11:22:33:44:55:66',
            'interface' => 'bridge1',
            'comment' => 'Static Camera',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/ip/arp');
        $this->assertContains('192.168.88.99', array_column($rows, 'address'));
    }

    public function test_dns_page_and_crud(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);

        $this->actingAs($admin)
            ->get(route('routers.ip.dns.index', $router))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Routers/Modules/Dns')->has('dnsSettings')->has('staticEntries'));

        $this->actingAs($admin)->post(route('routers.ip.dns.static.store', $router), [
            'name' => 'intranet.corp',
            'address' => '192.168.88.5',
            'ttl' => '1d',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/ip/dns/static');
        $this->assertContains('intranet.corp', array_column($rows, 'name'));
    }

    public function test_bridge_page_and_crud(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);

        $this->actingAs($admin)
            ->get(route('routers.bridge.index', $router))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Routers/Modules/Bridge')->has('bridges')->has('ports'));

        $this->actingAs($admin)->post(route('routers.bridge.bridges.store', $router), [
            'name' => 'bridge-vlan',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/interface/bridge');
        $this->assertContains('bridge-vlan', array_column($rows, 'name'));
    }

    public function test_ip_services_page_and_crud(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);

        $this->actingAs($admin)
            ->get(route('routers.ip.services.index', $router))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Routers/Modules/IpServices')->has('services'));

        $this->actingAs($admin)->put(route('routers.ip.services.update', $router), [
            'id' => '*6',
            'port' => 8292,
            'address' => '192.168.88.0/24',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/ip/service');
        $item = collect($rows)->first(fn ($r) => ($r['.id'] ?? null) === '*6');
        $this->assertEquals('8292', $item['port']);
    }

    public function test_system_users_page_and_crud(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);

        $this->actingAs($admin)
            ->get(route('routers.system.users.index', $router))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Routers/Modules/SystemUsers')->has('users')->has('groups')->has('activeUsers'));

        $this->actingAs($admin)->post(route('routers.system.users.store', $router), [
            'name' => 'engineer1',
            'group' => 'write',
            'password' => 'secret99',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/user');
        $this->assertContains('engineer1', array_column($rows, 'name'));
    }

    public function test_system_page_and_crud(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);

        $this->actingAs($admin)
            ->get(route('routers.system.index', $router))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Routers/Modules/System')->has('resource')->has('schedulers')->has('identity'));

        $this->actingAs($admin)->post(route('routers.system.schedulers.store', $router), [
            'name' => 'hourly-check',
            'interval' => '1h',
            'on_event' => '/ping 8.8.8.8 count=2',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/system/scheduler');
        $this->assertContains('hourly-check', array_column($rows, 'name'));

        // Reboot
        $this->actingAs($admin)->post(route('routers.system.reboot', $router))->assertSessionHas('success');
    }

    public function test_ip_pool_page_and_crud(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);

        $this->actingAs($admin)
            ->get(route('routers.ip.pool.index', $router))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Routers/Modules/IpPool')->has('pools', 3)->has('used', 3));

        // Create Pool
        $this->actingAs($admin)->post(route('routers.ip.pool.store', $router), [
            'name' => 'vpn-pool',
            'ranges' => '10.10.10.10-10.10.10.100',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/ip/pool');
        $this->assertContains('vpn-pool', array_column($rows, 'name'));

        // Update Pool
        $this->actingAs($admin)->put(route('routers.ip.pool.update', $router), [
            'id' => '*1',
            'name' => 'dhcp-pool-updated',
            'ranges' => '192.168.88.20-192.168.88.254',
        ])->assertSessionHas('success');

        $updated = app(ResourceClient::class)->list($router->fresh('credentials'), '/ip/pool');
        $this->assertContains('dhcp-pool-updated', array_column($updated, 'name'));

        // Delete Pool
        $this->actingAs($admin)->delete(route('routers.ip.pool.destroy', $router), [
            'id' => '*2',
        ])->assertSessionHas('success');

        $remaining = app(ResourceClient::class)->list($router->fresh('credentials'), '/ip/pool');
        $this->assertNotContains('hs-pool-1', array_column($remaining, 'name'));
    }

    public function test_ip_routes_page_and_crud(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);

        $this->actingAs($admin)
            ->get(route('routers.ip.routes.index', $router))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Routers/Modules/Routes')->has('routes', 1));

        // Create route
        $this->actingAs($admin)->post(route('routers.ip.routes.store', $router), [
            'dst_address' => '10.0.0.0/8',
            'gateway' => '192.168.88.254',
            'distance' => 2,
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/ip/route');
        $this->assertContains('10.0.0.0/8', array_column($rows, 'dst-address'));
    }

    public function test_router_backups_page(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);

        $this->actingAs($admin)
            ->get(route('routers.backups.index', $router))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Routers/Modules/Backups')->has('backups'));
    }

    public function test_dhcp_client_page_and_crud(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);

        $this->actingAs($admin)
            ->get(route('routers.ip.dhcp-client.index', $router))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Routers/Modules/DhcpClient')->has('clients', 1));

        // Create DHCP client
        $this->actingAs($admin)->post(route('routers.ip.dhcp-client.store', $router), [
            'interface' => 'ether2',
            'use_peer_dns' => 'yes',
            'use_peer_ntp' => 'yes',
            'add_default_route' => 'yes',
            'default_route_distance' => 2,
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/ip/dhcp-client');
        $this->assertContains('ether2', array_column($rows, 'interface'));

        // Toggle
        $this->actingAs($admin)->post(route('routers.ip.dhcp-client.toggle', $router), [
            'id' => '*1',
            'disable' => true,
        ])->assertSessionHas('success');

        // Renew & Release
        $this->actingAs($admin)->post(route('routers.ip.dhcp-client.renew', $router), ['id' => '*1'])->assertSessionHas('success');
        $this->actingAs($admin)->post(route('routers.ip.dhcp-client.release', $router), ['id' => '*1'])->assertSessionHas('success');
    }

    public function test_neighbors_page(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);

        $this->actingAs($admin)
            ->get(route('routers.ip.neighbors.index', $router))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Routers/Modules/Neighbors')->has('neighbors', 2));
    }

    public function test_files_page_and_backup(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);

        $this->actingAs($admin)
            ->get(route('routers.files.index', $router))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Routers/Modules/Files')->has('files', 3));

        // Create backup
        $this->actingAs($admin)->post(route('routers.files.backup', $router), [
            'name' => 'manual-test-backup',
        ])->assertSessionHas('success');
    }

    public function test_log_page(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);

        $this->actingAs($admin)
            ->get(route('routers.log.index', $router))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Routers/Modules/Log')->has('logs', 3));
    }

    public function test_tools_netwatch_crud(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);

        // Create netwatch
        $this->actingAs($admin)->post(route('routers.tools.netwatch.store', $router), [
            'host' => '9.9.9.9',
            'interval' => '30s',
            'timeout' => '500ms',
            'comment' => 'Quad9 DNS',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/tool/netwatch');
        $this->assertContains('9.9.9.9', array_column($rows, 'host'));

        // Toggle
        $this->actingAs($admin)->post(route('routers.tools.netwatch.toggle', $router), [
            'id' => '*1',
            'disable' => true,
        ])->assertSessionHas('success');
    }

    public function test_wireguard_page_and_crud(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);

        $this->actingAs($admin)
            ->get(route('routers.wireguard.index', $router))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Routers/Modules/Wireguard')->has('interfaces', 1)->has('peers', 2));

        // Create Wireguard interface
        $this->actingAs($admin)->post(route('routers.wireguard.interfaces.store', $router), [
            'name' => 'wg-office',
            'listen_port' => 51820,
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/interface/wireguard');
        $this->assertContains('wg-office', array_column($rows, 'name'));

        // Create peer
        $this->actingAs($admin)->post(route('routers.wireguard.peers.store', $router), [
            'interface' => 'wireguard1',
            'public_key' => 'YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=',
            'allowed_address' => '10.20.0.2/32',
        ])->assertSessionHas('success');
    }

    public function test_radius_page_and_crud(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);

        $this->actingAs($admin)
            ->get(route('routers.radius.index', $router))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Routers/Modules/Radius')->has('servers', 1));

        // Create RADIUS server
        $this->actingAs($admin)->post(route('routers.radius.store', $router), [
            'service' => 'hotspot,ppp',
            'address' => '10.0.0.254',
            'secret' => 'testing123',
        ])->assertSessionHas('success');

        $rows = app(ResourceClient::class)->list($router->fresh('credentials'), '/radius');
        $this->assertContains('10.0.0.254', array_column($rows, 'address'));

        // Toggle
        $this->actingAs($admin)->post(route('routers.radius.toggle', $router), [
            'id' => '*1',
            'disable' => true,
        ])->assertSessionHas('success');
    }

    public function test_terminal_page_and_execute(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);

        $this->actingAs($admin)
            ->get(route('routers.terminal.index', $router))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Routers/Modules/Terminal'));

        // Execute command
        $response = $this->actingAs($admin)->postJson(route('routers.terminal.execute', $router), [
            'command' => '/system/identity/print',
        ]);

        $response->assertOk()->assertJson(['success' => true]);
    }

    public function test_all_winbox_modules_render_successfully(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);

        $routes = [
            'routers.show',
            'routers.interfaces.index',
            'routers.wireguard.index',
            'routers.bridge.index',
            'routers.ppp.index',
            'routers.ip.arp.index',
            'routers.ip.addresses.index',
            'routers.ip.dhcp-client.index',
            'routers.ip.dhcp.index',
            'routers.ip.dns.index',
            'routers.firewall.index',
            'routers.hotspot.index',
            'routers.ip.neighbors.index',
            'routers.ip.pool.index',
            'routers.ip.routes.index',
            'routers.ip.services.index',
            'routers.queues.index',
            'routers.files.index',
            'routers.log.index',
            'routers.radius.index',
            'routers.system.index',
            'routers.system.users.index',
            'routers.backups.index',
            'routers.tools.index',
            'routers.terminal.index',
        ];

        foreach ($routes as $route) {
            $this->actingAs($admin)
                ->get(route($route, $router))
                ->assertOk();
        }
    }

    public function test_interface_traffic_endpoint(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);

        $response = $this->actingAs($admin)
            ->getJson(route('routers.interfaces.traffic', ['router' => $router, 'interface' => 'ether1']));

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'interface' => 'ether1',
            ])
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

    public function test_dashboard_with_conditional_widgets_and_traffic(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);

        // 1. Default dashboard visit
        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->has('counts')
                ->has('routers')
                ->has('enabledWidgets')
                ->has('liveStats.ppp')
                ->has('liveStats.hotspot')
            );

        // 2. PPP only (Hotspot must be null, never queried from MikroTik)
        $this->actingAs($admin)
            ->get(route('dashboard', ['router_id' => $router->id, 'widgets' => 'fleet,traffic,ppp']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('liveStats.hotspot', null)
                ->has('liveStats.ppp')
                ->where('liveStats.ppp.active', 1)
            );

        // 3. Hotspot only (PPP must be null, never queried from MikroTik)
        $this->actingAs($admin)
            ->get(route('dashboard', ['router_id' => $router->id, 'widgets' => 'fleet,traffic,hotspot']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('liveStats.ppp', null)
                ->has('liveStats.hotspot')
                ->where('liveStats.hotspot.active', 1)
            );

        // 4. Fleet only (Zero MikroTik module load)
        $this->actingAs($admin)
            ->get(route('dashboard', ['router_id' => $router->id, 'widgets' => 'fleet']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('liveStats.ppp', null)
                ->where('liveStats.hotspot', null)
                ->where('liveStats.dhcp', null)
                ->where('liveStats.system', null)
            );

        // 5. JSON request (e.g. background polling / AJAX)
        $this->actingAs($admin)
            ->getJson(route('dashboard', ['router_id' => $router->id, 'widgets' => 'fleet,ppp']))
            ->assertOk()
            ->assertJsonPath('liveStats.hotspot', null)
            ->assertJsonStructure([
                'counts',
                'routers',
                'enabledWidgets',
                'liveStats',
            ]);
    }

    public function test_tools_torch_sniffs_interface_packets(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);

        $response = $this->actingAs($admin)->postJson(route('routers.tools.torch', $router), [
            'interface' => 'ether1',
            'protocol' => 'any',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'interface',
                'count',
                'rows' => [
                    '*' => ['src-address', 'dst-address', 'protocol', 'tx-rate', 'rx-rate', 'packets'],
                ],
            ]);
    }

    public function test_hotspot_vouchers_can_be_generated_in_batch(): void
    {
        $operator = $this->makeUser(role: Permissions::ROLE_NETWORK_OPERATOR);
        $router = $this->router($operator->organization_id);

        $response = $this->actingAs($operator)->postJson(route('routers.hotspot.vouchers.generate', $router), [
            'quantity' => 5,
            'server' => 'all',
            'profile' => 'default',
            'prefix' => 'tst-',
            'length' => 4,
            'charset' => 'numeric',
            'limit_uptime' => '1h',
            'price' => 5000,
            'comment' => 'vc',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('count', 5);

        $this->assertCount(5, $response->json('vouchers'));

        $users = app(ResourceClient::class)->list($router->fresh('credentials'), '/ip/hotspot/user');
        $matches = array_filter($users, fn ($u) => str_starts_with($u['name'] ?? '', 'tst-'));
        $this->assertCount(5, $matches);
    }

    public function test_ppp_secret_can_be_isolated_and_restored(): void
    {
        $operator = $this->makeUser(role: Permissions::ROLE_NETWORK_OPERATOR);
        $router = $this->router($operator->organization_id);

        // 1. Create a secret
        $this->actingAs($operator)->post(route('routers.ppp.secrets.store', $router), [
            'name' => 'client-to-isolate',
            'password' => 'secret123',
            'profile' => 'default',
            'service' => 'pppoe',
            'comment' => 'Paket Platinum',
        ]);

        $client = app(ResourceClient::class);
        $secrets = $client->list($router->fresh('credentials'), '/ppp/secret');
        $target = collect($secrets)->firstWhere('name', 'client-to-isolate');
        $this->assertNotNull($target);

        // 2. Isolate
        $this->actingAs($operator)->post(route('routers.ppp.secrets.isolate', $router), [
            'id' => $target['.id'],
        ])->assertSessionHas('success');

        $secrets = $client->list($router->fresh('credentials'), '/ppp/secret');
        $isolated = collect($secrets)->firstWhere('name', 'client-to-isolate');
        $this->assertSame('isolir', $isolated['profile']);
        $this->assertStringContainsString('[ORIG:default]', $isolated['comment']);

        // 3. Restore
        $this->actingAs($operator)->post(route('routers.ppp.secrets.restore', $router), [
            'id' => $target['.id'],
        ])->assertSessionHas('success');

        $secrets = $client->list($router->fresh('credentials'), '/ppp/secret');
        $restored = collect($secrets)->firstWhere('name', 'client-to-isolate');
        $this->assertSame('default', $restored['profile']);
        $this->assertStringNotContainsString('[ORIG:default]', $restored['comment']);
    }

    public function test_system_update_identity_and_reboot_and_schedulers(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);

        // 1. Update Identity
        $this->actingAs($admin)
            ->post(route('routers.system.identity.update', $router), [
                'name' => 'HQ-Core-Router',
            ])
            ->assertSessionHas('success');

        $this->assertSame('HQ-Core-Router', $router->fresh()->name);

        $client = app(ResourceClient::class);
        $identity = $client->list($router->fresh('credentials'), '/system/identity');
        $this->assertSame('HQ-Core-Router', $identity[0]['name'] ?? null);
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.identity_set', 'router_id' => $router->id]);

        // 2. Scheduler CRUD
        $this->actingAs($admin)
            ->post(route('routers.system.schedulers.store', $router), [
                'name' => 'daily-log',
                'interval' => '1d',
                'on_event' => '/log print',
            ])
            ->assertSessionHas('success');

        $schedulers = $client->list($router->fresh('credentials'), '/system/scheduler');
        $created = collect($schedulers)->firstWhere('name', 'daily-log');
        $this->assertNotNull($created);

        // Toggle
        $this->actingAs($admin)
            ->post(route('routers.system.schedulers.toggle', $router), [
                'id' => $created['.id'],
                'disable' => true,
            ])
            ->assertSessionHas('success');

        // Destroy
        $this->actingAs($admin)
            ->delete(route('routers.system.schedulers.destroy', $router), [
                'id' => $created['.id'],
            ])
            ->assertSessionHas('success');

        // 3. Reboot
        $this->actingAs($admin)
            ->post(route('routers.system.reboot', $router))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('audit_logs', ['action' => 'system.reboot', 'router_id' => $router->id]);
    }

    public function test_files_backup_creation_and_removal(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);

        // 1. Create Backup
        $this->actingAs($admin)
            ->post(route('routers.files.backup', $router), [
                'name' => 'test-backup',
            ])
            ->assertSessionHas('success');

        $client = app(ResourceClient::class);
        $files = $client->list($router->fresh('credentials'), '/file');
        $created = collect($files)->firstWhere('name', 'test-backup.backup');
        $this->assertNotNull($created);

        // 2. Destroy File
        $this->actingAs($admin)
            ->delete(route('routers.files.destroy', $router), [
                'id' => $created['.id'],
            ])
            ->assertSessionHas('success');

        $filesAfter = $client->list($router->fresh('credentials'), '/file');
        $this->assertNull(collect($filesAfter)->firstWhere('.id', $created['.id']));
    }

    public function test_dhcp_server_pools_resolved(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);

        $this->actingAs($admin)
            ->get(route('routers.ip.dhcp.index', $router))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Routers/Modules/Dhcp')
                ->has('servers')
                ->where('servers.0.address-pool', 'dhcp-pool-1')
            );
    }

    public function test_operator_can_batch_manage_dhcp_leases(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);
        $client = app(ResourceClient::class);

        // Add lease
        $this->actingAs($admin)->post(route('routers.ip.dhcp.leases.store', $router), [
            'address' => '192.168.88.99',
            'mac_address' => '00:11:22:33:44:99',
            'server' => 'dhcp1',
            'comment' => 'test-lease',
        ])->assertSessionHas('success');

        $leases = $client->list($router->fresh('credentials'), '/ip/dhcp-server/lease');
        $target = collect($leases)->firstWhere('address', '192.168.88.99');
        $this->assertNotNull($target);

        // Batch disable
        $this->actingAs($admin)->post(route('routers.ip.dhcp.leases.batch', $router), [
            'action' => 'disable',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $leases = $client->list($router->fresh('credentials'), '/ip/dhcp-server/lease');
        $updated = collect($leases)->firstWhere('address', '192.168.88.99');
        $this->assertNotNull($updated);
        $this->assertSame('true', $updated['disabled']);

        // Batch make static
        $this->actingAs($admin)->post(route('routers.ip.dhcp.leases.batch', $router), [
            'action' => 'make_static',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        // Batch destroy
        $this->actingAs($admin)->post(route('routers.ip.dhcp.leases.batch', $router), [
            'action' => 'destroy',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $leasesAfter = $client->list($router->fresh('credentials'), '/ip/dhcp-server/lease');
        $this->assertNull(collect($leasesAfter)->firstWhere('address', '192.168.88.99'));
    }

    public function test_operator_can_batch_manage_firewall_filters_and_counters(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);
        $client = app(ResourceClient::class);

        // Add filter
        $this->actingAs($admin)->post(route('routers.firewall.store', $router), [
            'chain' => 'forward',
            'action' => 'drop',
            'src_address' => '10.10.10.10',
            'comment' => 'test-batch-filter',
        ])->assertSessionHas('success');

        $filters = $client->list($router->fresh('credentials'), '/ip/firewall/filter');
        $target = collect($filters)->firstWhere('comment', 'test-batch-filter');
        $this->assertNotNull($target);

        // Reset counters
        $this->actingAs($admin)->post(route('routers.firewall.reset-counters', $router), [
            'id' => $target['.id'],
        ])->assertSessionHas('success');

        // Batch disable
        $this->actingAs($admin)->post(route('routers.firewall.batch', $router), [
            'action' => 'disable',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $filters = $client->list($router->fresh('credentials'), '/ip/firewall/filter');
        $updated = collect($filters)->firstWhere('comment', 'test-batch-filter');
        $this->assertNotNull($updated);
        $this->assertSame('true', $updated['disabled']);

        // Batch destroy
        $this->actingAs($admin)->post(route('routers.firewall.batch', $router), [
            'action' => 'destroy',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $filtersAfter = $client->list($router->fresh('credentials'), '/ip/firewall/filter');
        $this->assertNull(collect($filtersAfter)->firstWhere('comment', 'test-batch-filter'));
    }

    public function test_operator_can_batch_manage_firewall_nat_and_counters(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);
        $client = app(ResourceClient::class);

        // Add NAT
        $this->actingAs($admin)->post(route('routers.firewall.nat.store', $router), [
            'chain' => 'srcnat',
            'action' => 'masquerade',
            'out_interface' => 'ether1',
            'comment' => 'test-batch-nat',
        ])->assertSessionHas('success');

        $nats = $client->list($router->fresh('credentials'), '/ip/firewall/nat');
        $target = collect($nats)->firstWhere('comment', 'test-batch-nat');
        $this->assertNotNull($target);

        // Reset counters
        $this->actingAs($admin)->post(route('routers.firewall.nat.reset-counters', $router), [
            'id' => $target['.id'],
        ])->assertSessionHas('success');

        // Batch disable
        $this->actingAs($admin)->post(route('routers.firewall.nat.batch', $router), [
            'action' => 'disable',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $nats = $client->list($router->fresh('credentials'), '/ip/firewall/nat');
        $updated = collect($nats)->firstWhere('comment', 'test-batch-nat');
        $this->assertNotNull($updated);
        $this->assertSame('true', $updated['disabled']);

        // Batch destroy
        $this->actingAs($admin)->post(route('routers.firewall.nat.batch', $router), [
            'action' => 'destroy',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $natsAfter = $client->list($router->fresh('credentials'), '/ip/firewall/nat');
        $this->assertNull(collect($natsAfter)->firstWhere('comment', 'test-batch-nat'));
    }

    public function test_operator_can_batch_manage_interfaces(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);
        $client = app(ResourceClient::class);

        $interfaces = $client->list($router->fresh('credentials'), '/interface');
        $target = $interfaces[0] ?? null;
        $this->assertNotNull($target);

        // Batch disable
        $this->actingAs($admin)->post(route('routers.interfaces.batch', $router), [
            'action' => 'disable',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $interfaces = $client->list($router->fresh('credentials'), '/interface');
        $updated = collect($interfaces)->firstWhere('name', $target['name']);
        $this->assertNotNull($updated);
        $this->assertSame('true', $updated['disabled']);

        // Batch reset counters
        $this->actingAs($admin)->post(route('routers.interfaces.batch', $router), [
            'action' => 'reset_counters',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        // Batch enable
        $this->actingAs($admin)->post(route('routers.interfaces.batch', $router), [
            'action' => 'enable',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $interfaces = $client->list($router->fresh('credentials'), '/interface');
        $updated = collect($interfaces)->firstWhere('name', $target['name']);
        $this->assertNotNull($updated);
        $this->assertSame('false', $updated['disabled']);
    }

    public function test_operator_can_batch_manage_arp(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);
        $client = app(ResourceClient::class);

        // Add ARP
        $this->actingAs($admin)->post(route('routers.ip.arp.store', $router), [
            'address' => '192.168.88.77',
            'mac_address' => '00:11:22:33:44:77',
            'interface' => 'bridge1',
            'comment' => 'test-batch-arp',
        ])->assertSessionHas('success');

        $arps = $client->list($router->fresh('credentials'), '/ip/arp');
        $target = collect($arps)->firstWhere('address', '192.168.88.77');
        $this->assertNotNull($target);

        // Batch disable
        $this->actingAs($admin)->post(route('routers.ip.arp.batch', $router), [
            'action' => 'disable',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $arps = $client->list($router->fresh('credentials'), '/ip/arp');
        $updated = collect($arps)->firstWhere('address', '192.168.88.77');
        $this->assertNotNull($updated);
        $this->assertSame('true', $updated['disabled']);

        // Batch make static
        $this->actingAs($admin)->post(route('routers.ip.arp.batch', $router), [
            'action' => 'make_static',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        // Batch destroy
        $this->actingAs($admin)->post(route('routers.ip.arp.batch', $router), [
            'action' => 'destroy',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $arpsAfter = $client->list($router->fresh('credentials'), '/ip/arp');
        $this->assertNull(collect($arpsAfter)->firstWhere('address', '192.168.88.77'));
    }

    public function test_admin_can_batch_manage_ip_addresses(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);
        $client = app(ResourceClient::class);

        // Add IP address
        $this->actingAs($admin)->post(route('routers.ip.addresses.store', $router), [
            'address' => '172.16.50.1/24',
            'interface' => 'ether1',
            'comment' => 'test-batch-ip',
        ])->assertSessionHas('success');

        $rows = $client->list($router->fresh('credentials'), '/ip/address');
        $target = collect($rows)->firstWhere('address', '172.16.50.1/24');
        $this->assertNotNull($target);

        // Batch disable
        $this->actingAs($admin)->post(route('routers.ip.addresses.batch', $router), [
            'action' => 'disable',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $rows = $client->list($router->fresh('credentials'), '/ip/address');
        $updated = collect($rows)->firstWhere('address', '172.16.50.1/24');
        $this->assertNotNull($updated);
        $this->assertSame('true', $updated['disabled']);

        // Batch enable
        $this->actingAs($admin)->post(route('routers.ip.addresses.batch', $router), [
            'action' => 'enable',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $rows = $client->list($router->fresh('credentials'), '/ip/address');
        $updated = collect($rows)->firstWhere('address', '172.16.50.1/24');
        $this->assertNotNull($updated);
        $this->assertSame('false', $updated['disabled']);

        // Batch destroy
        $this->actingAs($admin)->post(route('routers.ip.addresses.batch', $router), [
            'action' => 'destroy',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $rowsAfter = $client->list($router->fresh('credentials'), '/ip/address');
        $this->assertNull(collect($rowsAfter)->firstWhere('address', '172.16.50.1/24'));
    }

    public function test_admin_can_batch_manage_routes(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);
        $client = app(ResourceClient::class);

        // Add static route
        $this->actingAs($admin)->post(route('routers.ip.routes.store', $router), [
            'dst_address' => '192.168.99.0/24',
            'gateway' => '192.168.88.254',
            'distance' => 5,
            'comment' => 'test-batch-route',
        ])->assertSessionHas('success');

        $rows = $client->list($router->fresh('credentials'), '/ip/route');
        $target = collect($rows)->firstWhere('dst-address', '192.168.99.0/24');
        $this->assertNotNull($target);

        // Batch disable
        $this->actingAs($admin)->post(route('routers.ip.routes.batch', $router), [
            'action' => 'disable',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $rows = $client->list($router->fresh('credentials'), '/ip/route');
        $updated = collect($rows)->firstWhere('dst-address', '192.168.99.0/24');
        $this->assertNotNull($updated);
        $this->assertSame('true', $updated['disabled']);

        // Batch enable
        $this->actingAs($admin)->post(route('routers.ip.routes.batch', $router), [
            'action' => 'enable',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $rows = $client->list($router->fresh('credentials'), '/ip/route');
        $updated = collect($rows)->firstWhere('dst-address', '192.168.99.0/24');
        $this->assertNotNull($updated);
        $this->assertSame('false', $updated['disabled']);

        // Batch destroy
        $this->actingAs($admin)->post(route('routers.ip.routes.batch', $router), [
            'action' => 'destroy',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $rowsAfter = $client->list($router->fresh('credentials'), '/ip/route');
        $this->assertNull(collect($rowsAfter)->firstWhere('dst-address', '192.168.99.0/24'));
    }

    public function test_admin_can_batch_manage_bridges_and_ports(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);
        $client = app(ResourceClient::class);

        // Add Bridge
        $this->actingAs($admin)->post(route('routers.bridge.bridges.store', $router), [
            'name' => 'br-batch-test',
            'arp' => 'enabled',
            'comment' => 'test-bridge-batch',
        ])->assertSessionHas('success');

        $bridges = $client->list($router->fresh('credentials'), '/interface/bridge');
        $targetBridge = collect($bridges)->firstWhere('name', 'br-batch-test');
        $this->assertNotNull($targetBridge);

        // Add Bridge Port
        $this->actingAs($admin)->post(route('routers.bridge.ports.store', $router), [
            'interface' => 'ether5',
            'bridge' => 'br-batch-test',
            'priority' => '0x80',
            'comment' => 'test-port-batch',
        ])->assertSessionHas('success');

        $ports = $client->list($router->fresh('credentials'), '/interface/bridge/port');
        $targetPort = collect($ports)->firstWhere('interface', 'ether5');
        $this->assertNotNull($targetPort);

        // Batch disable port
        $this->actingAs($admin)->post(route('routers.bridge.ports.batch', $router), [
            'action' => 'disable',
            'ids' => [$targetPort['.id']],
        ])->assertSessionHas('success');

        // Batch destroy port
        $this->actingAs($admin)->post(route('routers.bridge.ports.batch', $router), [
            'action' => 'destroy',
            'ids' => [$targetPort['.id']],
        ])->assertSessionHas('success');

        $portsAfter = $client->list($router->fresh('credentials'), '/interface/bridge/port');
        $this->assertNull(collect($portsAfter)->firstWhere('interface', 'ether5'));

        // Batch disable bridge
        $this->actingAs($admin)->post(route('routers.bridge.bridges.batch', $router), [
            'action' => 'disable',
            'ids' => [$targetBridge['.id']],
        ])->assertSessionHas('success');

        // Batch destroy bridge
        $this->actingAs($admin)->post(route('routers.bridge.bridges.batch', $router), [
            'action' => 'destroy',
            'ids' => [$targetBridge['.id']],
        ])->assertSessionHas('success');

        $bridgesAfter = $client->list($router->fresh('credentials'), '/interface/bridge');
        $this->assertNull(collect($bridgesAfter)->firstWhere('name', 'br-batch-test'));
    }

    public function test_admin_can_batch_manage_ip_pools(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);
        $client = app(ResourceClient::class);

        // Create Pool
        $this->actingAs($admin)->post(route('routers.ip.pool.store', $router), [
            'name' => 'pool-batch-test',
            'ranges' => '10.50.0.10-10.50.0.100',
            'next_pool' => 'none',
            'comment' => 'test-pool-batch',
        ])->assertSessionHas('success');

        $pools = $client->list($router->fresh('credentials'), '/ip/pool');
        $target = collect($pools)->firstWhere('name', 'pool-batch-test');
        $this->assertNotNull($target);

        // Batch destroy
        $this->actingAs($admin)->post(route('routers.ip.pool.batch', $router), [
            'action' => 'destroy',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $poolsAfter = $client->list($router->fresh('credentials'), '/ip/pool');
        $this->assertNull(collect($poolsAfter)->firstWhere('name', 'pool-batch-test'));
    }

    public function test_admin_can_batch_manage_dhcp_clients(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);
        $client = app(ResourceClient::class);

        // Add DHCP Client
        $this->actingAs($admin)->post(route('routers.ip.dhcp-client.store', $router), [
            'interface' => 'ether2',
            'use_peer_dns' => 'yes',
            'use_peer_ntp' => 'yes',
            'add_default_route' => 'yes',
            'default_route_distance' => 2,
            'comment' => 'test-batch-dhcp-client',
        ])->assertSessionHas('success');

        $rows = $client->list($router->fresh('credentials'), '/ip/dhcp-client');
        $target = collect($rows)->firstWhere('interface', 'ether2');
        $this->assertNotNull($target);

        // Batch disable
        $this->actingAs($admin)->post(route('routers.ip.dhcp-client.batch', $router), [
            'action' => 'disable',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $rows = $client->list($router->fresh('credentials'), '/ip/dhcp-client');
        $updated = collect($rows)->firstWhere('interface', 'ether2');
        $this->assertNotNull($updated);
        $this->assertSame('true', $updated['disabled']);

        // Batch enable
        $this->actingAs($admin)->post(route('routers.ip.dhcp-client.batch', $router), [
            'action' => 'enable',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        // Batch renew
        $this->actingAs($admin)->post(route('routers.ip.dhcp-client.batch', $router), [
            'action' => 'renew',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        // Batch release
        $this->actingAs($admin)->post(route('routers.ip.dhcp-client.batch', $router), [
            'action' => 'release',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        // Batch destroy
        $this->actingAs($admin)->post(route('routers.ip.dhcp-client.batch', $router), [
            'action' => 'destroy',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $rowsAfter = $client->list($router->fresh('credentials'), '/ip/dhcp-client');
        $this->assertNull(collect($rowsAfter)->firstWhere('interface', 'ether2'));
    }

    public function test_admin_can_batch_manage_static_dns(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);
        $client = app(ResourceClient::class);

        // Add static DNS
        $this->actingAs($admin)->post(route('routers.ip.dns.static.store', $router), [
            'name' => 'batch-test.internal',
            'address' => '10.0.0.99',
            'ttl' => '1d',
            'comment' => 'test-batch-dns',
        ])->assertSessionHas('success');

        $rows = $client->list($router->fresh('credentials'), '/ip/dns/static');
        $target = collect($rows)->firstWhere('name', 'batch-test.internal');
        $this->assertNotNull($target);

        // Batch disable
        $this->actingAs($admin)->post(route('routers.ip.dns.static.batch', $router), [
            'action' => 'disable',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $rows = $client->list($router->fresh('credentials'), '/ip/dns/static');
        $updated = collect($rows)->firstWhere('name', 'batch-test.internal');
        $this->assertNotNull($updated);
        $this->assertSame('true', $updated['disabled']);

        // Batch enable
        $this->actingAs($admin)->post(route('routers.ip.dns.static.batch', $router), [
            'action' => 'enable',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        // Batch destroy
        $this->actingAs($admin)->post(route('routers.ip.dns.static.batch', $router), [
            'action' => 'destroy',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $rowsAfter = $client->list($router->fresh('credentials'), '/ip/dns/static');
        $this->assertNull(collect($rowsAfter)->firstWhere('name', 'batch-test.internal'));
    }

    public function test_admin_can_batch_manage_ip_services(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);
        $client = app(ResourceClient::class);

        $services = $client->list($router->fresh('credentials'), '/ip/service');
        $target = $services[0] ?? null;
        $this->assertNotNull($target);

        // Batch disable
        $this->actingAs($admin)->post(route('routers.ip.services.batch', $router), [
            'action' => 'disable',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $services = $client->list($router->fresh('credentials'), '/ip/service');
        $updated = collect($services)->firstWhere('name', $target['name']);
        $this->assertNotNull($updated);
        $this->assertSame('true', $updated['disabled']);

        // Batch enable
        $this->actingAs($admin)->post(route('routers.ip.services.batch', $router), [
            'action' => 'enable',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $services = $client->list($router->fresh('credentials'), '/ip/service');
        $updated = collect($services)->firstWhere('name', $target['name']);
        $this->assertNotNull($updated);
        $this->assertSame('false', $updated['disabled']);
    }

    public function test_admin_can_batch_manage_system_users(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);
        $client = app(ResourceClient::class);

        // Create new user
        $this->actingAs($admin)->post(route('routers.system.users.store', $router), [
            'name' => 'batch-noc-user',
            'group' => 'read',
            'password' => 'secret123',
            'comment' => 'test-batch',
        ])->assertSessionHas('success');

        $users = $client->list($router->fresh('credentials'), '/user');
        $target = collect($users)->firstWhere('name', 'batch-noc-user');
        $this->assertNotNull($target);

        // Batch disable
        $this->actingAs($admin)->post(route('routers.system.users.batch', $router), [
            'action' => 'disable',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $usersAfterDisable = $client->list($router->fresh('credentials'), '/user');
        $this->assertSame('true', collect($usersAfterDisable)->firstWhere('name', 'batch-noc-user')['disabled']);

        // Batch enable
        $this->actingAs($admin)->post(route('routers.system.users.batch', $router), [
            'action' => 'enable',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $usersAfterEnable = $client->list($router->fresh('credentials'), '/user');
        $this->assertSame('false', collect($usersAfterEnable)->firstWhere('name', 'batch-noc-user')['disabled']);

        // Batch destroy
        $this->actingAs($admin)->post(route('routers.system.users.batch', $router), [
            'action' => 'destroy',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $usersFinal = $client->list($router->fresh('credentials'), '/user');
        $this->assertNull(collect($usersFinal)->firstWhere('name', 'batch-noc-user'));
    }

    public function test_admin_can_batch_manage_wireguard_peers(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);
        $client = app(ResourceClient::class);

        // Add peer
        $this->actingAs($admin)->post(route('routers.wireguard.peers.store', $router), [
            'interface' => 'wireguard1',
            'public_key' => 'WmF0Y2hUZXN0S2V5MTIzNDU2Nzg5MDEyMzQ1Njc4OTA=',
            'allowed_address' => '10.99.0.2/32',
            'comment' => 'batch-peer-test',
        ])->assertSessionHas('success');

        $peers = $client->list($router->fresh('credentials'), '/interface/wireguard/peers');
        $target = collect($peers)->firstWhere('comment', 'batch-peer-test');
        $this->assertNotNull($target);

        // Batch disable
        $this->actingAs($admin)->post(route('routers.wireguard.peers.batch', $router), [
            'action' => 'disable',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $peersAfter = $client->list($router->fresh('credentials'), '/interface/wireguard/peers');
        $this->assertSame('true', collect($peersAfter)->firstWhere('comment', 'batch-peer-test')['disabled']);

        // Batch destroy
        $this->actingAs($admin)->post(route('routers.wireguard.peers.batch', $router), [
            'action' => 'destroy',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $peersFinal = $client->list($router->fresh('credentials'), '/interface/wireguard/peers');
        $this->assertNull(collect($peersFinal)->firstWhere('comment', 'batch-peer-test'));
    }

    public function test_admin_can_batch_manage_radius_servers(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);
        $client = app(ResourceClient::class);

        // Add radius server
        $this->actingAs($admin)->post(route('routers.radius.store', $router), [
            'service' => 'ppp',
            'address' => '192.168.100.254',
            'secret' => 'radsecret999',
            'comment' => 'batch-rad-test',
        ])->assertSessionHas('success');

        $servers = $client->list($router->fresh('credentials'), '/radius');
        $target = collect($servers)->firstWhere('address', '192.168.100.254');
        $this->assertNotNull($target);

        // Batch disable
        $this->actingAs($admin)->post(route('routers.radius.batch', $router), [
            'action' => 'disable',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $serversAfter = $client->list($router->fresh('credentials'), '/radius');
        $this->assertSame('true', collect($serversAfter)->firstWhere('address', '192.168.100.254')['disabled']);

        // Batch destroy
        $this->actingAs($admin)->post(route('routers.radius.batch', $router), [
            'action' => 'destroy',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $serversFinal = $client->list($router->fresh('credentials'), '/radius');
        $this->assertNull(collect($serversFinal)->firstWhere('address', '192.168.100.254'));
    }

    public function test_admin_can_batch_manage_files(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = $this->router($admin->organization_id);
        $client = app(ResourceClient::class);

        // Create backup file
        $this->actingAs($admin)->post(route('routers.files.backup', $router), [
            'name' => 'batch-file-delete-test',
        ])->assertSessionHas('success');

        $files = $client->list($router->fresh('credentials'), '/file');
        $target = collect($files)->firstWhere('name', 'batch-file-delete-test.backup');
        $this->assertNotNull($target);

        // Batch destroy
        $this->actingAs($admin)->post(route('routers.files.batch', $router), [
            'action' => 'destroy',
            'ids' => [$target['.id']],
        ])->assertSessionHas('success');

        $filesFinal = $client->list($router->fresh('credentials'), '/file');
        $this->assertNull(collect($filesFinal)->firstWhere('name', 'batch-file-delete-test.backup'));
    }
}

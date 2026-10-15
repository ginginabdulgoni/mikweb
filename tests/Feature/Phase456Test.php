<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Automation\AutomationEngine;
use App\Domain\Automation\Models\AutomationRule;
use App\Domain\Backup\BackupService;
use App\Domain\Backup\Models\RouterBackup;
use App\Domain\Monitoring\Actions\CollectRouterMetrics;
use App\Domain\Router\Enums\RouterStatus;
use App\Domain\Router\Models\Router;
use App\Domain\Users\TwoFactorAuth;
use App\Models\User;
use App\Services\RouterOS\Adapters\MockRouterOsClient;
use App\Services\RouterOS\ConnectionManager;
use App\Support\Access\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class Phase456Test extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        MockRouterOsClient::reset();
        config(['mikweb.routeros.driver' => 'mock']);
    }

    // ── Phase 4: Monitoring ──────────────────────────────────────────

    public function test_metrics_collector_stores_a_sample_and_marks_online(): void
    {
        $router = Router::factory()->withApiCredential()->create();

        $metric = app(CollectRouterMetrics::class)->execute($router->fresh('credentials'));

        $this->assertTrue($metric->reachable);
        $this->assertNotNull($metric->mem_total);
        $this->assertSame(RouterStatus::Online, $router->fresh()->status);
        $this->assertDatabaseHas('router_metrics', ['router_id' => $router->id, 'reachable' => true]);
    }

    public function test_metrics_collector_records_unreachable_without_throwing(): void
    {
        $router = Router::factory()->withApiCredential()->create();
        MockRouterOsClient::$unreachableHosts = [$router->host];

        $metric = app(CollectRouterMetrics::class)->execute($router->fresh('credentials'));

        $this->assertFalse($metric->reachable);
        $this->assertSame(RouterStatus::Unreachable, $router->fresh()->status);
    }

    public function test_monitoring_page_requires_permission(): void
    {
        $user = $this->makeUser(role: Permissions::ROLE_READ_ONLY); // no monitoring.view
        $this->actingAs($user)->get(route('monitoring.index'))->assertForbidden();

        $viewer = $this->makeUser(role: Permissions::ROLE_MONITORING);
        $this->actingAs($viewer)->get(route('monitoring.index'))->assertOk();
    }

    // ── Phase 5: Backup ──────────────────────────────────────────────

    public function test_backup_export_writes_file_and_record(): void
    {
        Storage::fake('local');
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = Router::factory()->withApiCredential()->create(['organization_id' => $admin->organization_id]);

        $backup = app(BackupService::class)->export($router->fresh('credentials'), $admin->id);

        Storage::disk('local')->assertExists($backup->path);
        $this->assertGreaterThan(0, $backup->size);
        $this->assertDatabaseHas('router_backups', ['router_id' => $router->id, 'type' => 'export']);
    }

    // ── Phase 5: Automation ──────────────────────────────────────────

    public function test_automation_offline_trigger_fires_execution(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        Router::factory()->create(['organization_id' => $admin->organization_id, 'status' => RouterStatus::Unreachable]);

        $rule = AutomationRule::create([
            'organization_id' => $admin->organization_id,
            'name' => 'Alert offline',
            'trigger' => 'router_offline',
            'action' => 'notify_log',
            'enabled' => true,
        ]);

        $count = app(AutomationEngine::class)->evaluate($rule);

        $this->assertSame(1, $count);
        $this->assertDatabaseHas('automation_executions', ['automation_rule_id' => $rule->id, 'status' => 'triggered']);
        $this->assertNotNull($rule->fresh()->last_triggered_at);
    }

    // ── Phase 6: 2FA ─────────────────────────────────────────────────

    public function test_two_factor_enable_confirm_and_challenge_flow(): void
    {
        $user = $this->makeUser(role: Permissions::ROLE_READ_ONLY);
        $user->forceFill(['password' => Hash::make('secret-pass')])->save();

        // Enable → stores secret, unconfirmed.
        $this->actingAs($user)->post(route('settings.2fa.enable'))->assertRedirect();
        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);

        // Confirm with a valid TOTP.
        $tfa = app(TwoFactorAuth::class);
        $code = app(Google2FA::class)->getCurrentOtp($user->two_factor_secret);
        $this->actingAs($user)->post(route('settings.2fa.confirm'), ['code' => $code])->assertSessionHasNoErrors();
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);

        // Now a fresh login must divert to the challenge instead of logging in.
        auth()->logout();
        $this->post('/login', ['email' => $user->email, 'password' => 'secret-pass'])
            ->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();

        // Completing the challenge logs in.
        $code2 = app(Google2FA::class)->getCurrentOtp($user->two_factor_secret);
        $this->post(route('two-factor.challenge'), ['code' => $code2])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_login_is_rate_limited_after_repeated_failures(): void
    {
        $user = User::factory()->create(['password' => Hash::make('right')]);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_monitoring_with_kpis_and_single_router_collect(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = Router::factory()->withApiCredential()->create(['organization_id' => $admin->organization_id]);

        $this->actingAs($admin)
            ->get(route('monitoring.index', ['router' => $router->id, 'range' => '24h']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Monitoring/Index')
                ->has('kpis')
                ->has('chart.latency')
                ->where('selectedRouter', $router->id)
                ->where('range', '24h')
            );

        // Immediate single router collection
        $this->actingAs($admin)
            ->post(route('monitoring.routers.collect', $router))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('router_metrics', ['router_id' => $router->id]);
    }

    public function test_bulk_operations_reboot_sync_and_command(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router1 = Router::factory()->withApiCredential()->create(['organization_id' => $admin->organization_id]);
        $router2 = Router::factory()->withApiCredential()->create(['organization_id' => $admin->organization_id]);

        // Bulk sync
        $this->actingAs($admin)
            ->post(route('bulk.sync'), ['routers' => [$router1->id, $router2->id]])
            ->assertSessionHas('bulk_results');

        // Bulk reboot
        $this->actingAs($admin)
            ->post(route('bulk.reboot'), ['routers' => [$router1->id, $router2->id]])
            ->assertSessionHas('bulk_results');

        // Bulk command
        $this->actingAs($admin)
            ->post(route('bulk.command'), [
                'routers' => [$router1->id, $router2->id],
                'command' => '/system identity print',
            ])
            ->assertSessionHas('bulk_results');
    }

    public function test_global_search_returns_routers_backups_and_audit_logs(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = Router::factory()->withApiCredential()->create([
            'organization_id' => $admin->organization_id,
            'name' => 'Edge-Main-Router',
        ]);

        RouterBackup::create([
            'router_id' => $router->id,
            'created_by' => $admin->id,
            'type' => 'export',
            'disk' => 'local',
            'path' => 'backups/edge.rsc',
            'filename' => 'Edge-Main-Router-backup.rsc',
            'size' => 1024,
            'created_at' => now(),
        ]);

        AuditLog::create([
            'user_id' => $admin->id,
            'organization_id' => $admin->organization_id,
            'router_id' => $router->id,
            'action' => 'system.reboot',
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('search.index', ['q' => 'Edge-Main']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Search/Index')
                ->where('q', 'Edge-Main')
                ->has('routers', 1)
                ->has('backups', 1)
                ->has('auditLogs', 1)
            );
    }

    public function test_connection_clears_last_error_when_online(): void
    {
        $admin = $this->makeUser(role: Permissions::ROLE_NETWORK_ADMIN);
        $router = Router::factory()->withApiCredential()->create([
            'organization_id' => $admin->organization_id,
            'status' => RouterStatus::Unreachable,
            'last_error' => 'Connection to the router timed out (host unreachable or API port filtered).',
        ]);

        $result = app(ConnectionManager::class)->testConnection($router);

        $this->assertTrue($result->success);
        $this->assertSame(RouterStatus::Online, $router->fresh()->status);
        $this->assertNull($router->fresh()->last_error);
    }
}

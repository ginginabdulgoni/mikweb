<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Notification\Models\NotificationSetting;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use App\Support\Access\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotificationSettingsTest extends TestCase
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

    public function test_admin_can_view_notification_settings(): void
    {
        $admin = $this->makeUser(Permissions::ROLE_ORG_ADMIN);

        $res = $this->actingAs($admin)->get(route('settings.notifications.edit'));

        $res->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Notifications')
                ->has('settings')
            );
    }

    public function test_admin_can_update_notification_settings(): void
    {
        $admin = $this->makeUser(Permissions::ROLE_ORG_ADMIN);

        $res = $this->actingAs($admin)->post(route('settings.notifications.update'), [
            'telegram_bot_token' => '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11',
            'telegram_chat_id' => '-1001234567890',
            'whatsapp_target_phone' => '081234567890',
            'notify_router_down' => true,
            'notify_router_recovery' => true,
            'notify_high_cpu' => true,
            'high_cpu_threshold' => 85,
            'notify_daily_digest' => true,
            'notify_backup_completion' => true,
            'notify_security_alert' => true,
        ]);

        $res->assertRedirect();
        $this->assertDatabaseHas('notification_settings', [
            'organization_id' => $admin->organization_id,
            'telegram_chat_id' => '-1001234567890',
            'whatsapp_target_phone' => '081234567890',
            'high_cpu_threshold' => 85,
        ]);
    }

    public function test_admin_can_update_notification_settings_with_custom_templates_and_weagate(): void
    {
        $admin = $this->makeUser(Permissions::ROLE_ORG_ADMIN);

        $res = $this->actingAs($admin)->post(route('settings.notifications.update'), [
            'telegram_bot_token' => '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11',
            'telegram_chat_id' => '-1001234567890',
            'whatsapp_api_url' => 'https://mywifi.weagate.com',
            'whatsapp_api_token' => 'wg-token-123456',
            'whatsapp_device_id' => 'DEV-999',
            'whatsapp_target_phone' => '081234567890',
            'notify_router_down' => true,
            'notify_router_recovery' => true,
            'notify_high_cpu' => true,
            'high_cpu_threshold' => 85,
            'notify_daily_digest' => true,
            'notify_backup_completion' => true,
            'notify_security_alert' => true,
            'template_router_down' => '🚨 Router {router_name} ({router_host}) sedang DOWN pada {time}!',
            'template_high_cpu' => '⚠️ CPU {router_name} mencapai {cpu_load}%',
        ]);

        $res->assertRedirect();
        $this->assertDatabaseHas('notification_settings', [
            'organization_id' => $admin->organization_id,
            'telegram_chat_id' => '-1001234567890',
            'whatsapp_api_url' => 'https://mywifi.weagate.com',
            'whatsapp_device_id' => 'DEV-999',
            'whatsapp_target_phone' => '081234567890',
            'template_router_down' => '🚨 Router {router_name} ({router_host}) sedang DOWN pada {time}!',
            'template_high_cpu' => '⚠️ CPU {router_name} mencapai {cpu_load}%',
        ]);
    }

    public function test_admin_can_test_notification_dispatch_via_weagate_and_telegram(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
            'https://mywifi.weagate.com/*' => Http::response([
                'status' => true,
                'message' => 'Terkirim',
            ], 200),
        ]);

        $admin = $this->makeUser(Permissions::ROLE_ORG_ADMIN);
        NotificationSetting::create([
            'organization_id' => $admin->organization_id,
            'telegram_bot_token' => 'token-123',
            'telegram_chat_id' => '123456',
            'whatsapp_api_url' => 'https://mywifi.weagate.com',
            'whatsapp_api_token' => 'token-weagate',
            'whatsapp_device_id' => 'DEV-1',
            'whatsapp_target_phone' => '081234567890',
            'notify_router_down' => true,
        ]);

        $res = $this->actingAs($admin)->postJson(route('settings.notifications.test'), [
            'alert_type' => 'router_down',
        ]);

        $res->assertOk()
            ->assertJson([
                'success' => true,
                'telegram' => true,
                'whatsapp' => true,
            ]);
    }

    public function test_admin_can_test_whatsapp_channel_only_even_if_weagate_returns_string_status(): void
    {
        Http::fake([
            'https://mywifi.weagate.com/*' => Http::response([
                'status' => 'true',
                'message' => 'Pesan berhasil dikirim',
            ], 200),
        ]);

        $admin = $this->makeUser(Permissions::ROLE_ORG_ADMIN);
        NotificationSetting::create([
            'organization_id' => $admin->organization_id,
            'whatsapp_api_url' => 'https://mywifi.weagate.com',
            'whatsapp_api_token' => 'token-weagate',
            'whatsapp_device_id' => 'DEV-1',
            'whatsapp_target_phone' => '081234567890',
            'notify_router_down' => true,
        ]);

        $res = $this->actingAs($admin)->postJson(route('settings.notifications.test'), [
            'channel' => 'whatsapp',
        ]);

        $res->assertOk()
            ->assertJson([
                'success' => true,
                'channel' => 'whatsapp',
                'whatsapp' => true,
                'telegram' => false,
            ]);
    }

    public function test_admin_can_test_telegram_channel_only(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $admin = $this->makeUser(Permissions::ROLE_ORG_ADMIN);
        NotificationSetting::create([
            'organization_id' => $admin->organization_id,
            'telegram_bot_token' => 'token-123',
            'telegram_chat_id' => '123456',
            'notify_router_down' => true,
        ]);

        $res = $this->actingAs($admin)->postJson(route('settings.notifications.test'), [
            'channel' => 'telegram',
        ]);

        $res->assertOk()
            ->assertJson([
                'success' => true,
                'channel' => 'telegram',
                'telegram' => true,
                'whatsapp' => false,
            ]);
    }

    public function test_operator_cannot_access_notification_settings(): void
    {
        $user = $this->makeUser(Permissions::ROLE_NETWORK_OPERATOR);

        $this->actingAs($user)->get(route('settings.notifications.edit'))->assertForbidden();
    }
}

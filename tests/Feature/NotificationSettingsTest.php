<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Organization\Models\Organization;
use App\Models\User;
use App\Support\Access\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_operator_cannot_access_notification_settings(): void
    {
        $user = $this->makeUser(Permissions::ROLE_NETWORK_OPERATOR);

        $this->actingAs($user)->get(route('settings.notifications.edit'))->assertForbidden();
    }
}

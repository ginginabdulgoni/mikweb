<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_login_screen_renders(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_user_can_authenticate_and_login_timestamp_recorded(): void
    {
        $this->seedRoles();
        $user = User::factory()->create(['password' => Hash::make('secret-pass')]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret-pass',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_bad_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret-pass')]);

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_user_can_logout(): void
    {
        $this->seedRoles();
        $user = $this->makeUser();

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }
}

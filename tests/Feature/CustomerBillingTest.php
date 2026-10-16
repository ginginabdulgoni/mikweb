<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Organization\Models\Organization;
use App\Domain\Router\Models\Router;
use App\Models\User;
use App\Support\Access\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerBillingTest extends TestCase
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

    public function test_customer_index_renders_with_stats(): void
    {
        $user = $this->makeUser(Permissions::ROLE_NETWORK_OPERATOR);
        $router = Router::factory()->withApiCredential()->create(['organization_id' => $user->organization_id]);

        Customer::create([
            'organization_id' => $user->organization_id,
            'router_id' => $router->id,
            'service_type' => 'pppoe',
            'username' => 'cust-01',
            'customer_name' => 'Budi Santoso',
            'phone_number' => '08123456789',
            'monthly_bill' => 150000,
            'due_day' => 15,
            'billing_status' => 'unpaid',
        ]);

        $res = $this->actingAs($user)->get(route('customers.index'));

        $res->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Customers/Index')
                ->has('customers.data', 1)
                ->where('stats.total', 1)
                ->where('stats.unpaid', 1)
            );
    }

    public function test_can_create_customer(): void
    {
        $user = $this->makeUser(Permissions::ROLE_NETWORK_ADMIN);

        $res = $this->actingAs($user)->post(route('customers.store'), [
            'service_type' => 'pppoe',
            'username' => 'cust-02',
            'customer_name' => 'Dewi Lestari',
            'phone_number' => '08987654321',
            'monthly_bill' => 200000,
            'due_day' => 10,
            'billing_status' => 'unpaid',
        ]);

        $res->assertRedirect();
        $this->assertDatabaseHas('customers', [
            'username' => 'cust-02',
            'customer_name' => 'Dewi Lestari',
            'organization_id' => $user->organization_id,
        ]);
    }

    public function test_can_mark_customer_as_paid(): void
    {
        $user = $this->makeUser(Permissions::ROLE_NETWORK_OPERATOR);

        $cust = Customer::create([
            'organization_id' => $user->organization_id,
            'service_type' => 'pppoe',
            'username' => 'cust-03',
            'customer_name' => 'Ahmad Dani',
            'monthly_bill' => 100000,
            'due_day' => 5,
            'billing_status' => 'unpaid',
            'is_isolated' => true,
        ]);

        $res = $this->actingAs($user)->post(route('customers.mark-paid', $cust->id));

        $res->assertRedirect();
        $cust->refresh();
        $this->assertEquals('paid', $cust->billing_status);
        $this->assertFalse($cust->is_isolated);
        $this->assertNotNull($cust->last_paid_at);
    }

    public function test_read_only_user_cannot_access_customers(): void
    {
        $user = $this->makeUser(Permissions::ROLE_READ_ONLY);

        $this->actingAs($user)->get(route('customers.index'))->assertForbidden();
    }
}

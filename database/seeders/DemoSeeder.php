<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Organization\Models\Organization;
use App\Domain\Router\Enums\CredentialType;
use App\Domain\Router\Enums\Transport;
use App\Domain\Router\Models\Router;
use App\Models\User;
use App\Support\Access\Permissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Local/demo data: an organisation, one super admin, one network operator, and
 * a couple of routers (pointing at RFC-5737 documentation IPs so nothing real
 * is contacted). With MIKWEB_ROUTEROS_DRIVER=mock these behave like live
 * devices for the whole UI.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::firstOrCreate(
            ['slug' => 'acme-isp'],
            ['name' => 'Acme ISP', 'description' => 'Demo organisation']
        );

        $admin = User::firstOrCreate(
            ['email' => 'admin@mikweb.local'],
            [
                'organization_id' => $org->id,
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'is_super_admin' => true,
                'email_verified_at' => now(),
            ]
        );
        $admin->syncRoles([Permissions::ROLE_SUPER_ADMIN]);

        $operator = User::firstOrCreate(
            ['email' => 'operator@mikweb.local'],
            [
                'organization_id' => $org->id,
                'name' => 'Nia Operator',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        $operator->syncRoles([Permissions::ROLE_NETWORK_OPERATOR]);

        $routers = [
            ['name' => 'Core-RB5009', 'host' => '198.51.100.1', 'site' => 'HQ', 'isp' => 'Acme ISP'],
            ['name' => 'Edge-CCR2004', 'host' => '198.51.100.2', 'site' => 'POP-1', 'isp' => 'Acme ISP'],
        ];

        foreach ($routers as $data) {
            $router = Router::firstOrCreate(
                ['organization_id' => $org->id, 'host' => $data['host']],
                [
                    'name' => $data['name'],
                    'site' => $data['site'],
                    'isp' => $data['isp'],
                    'preferred_transport' => Transport::Api,
                    'created_by' => $admin->id,
                ]
            );

            $router->credentials()->firstOrCreate(
                ['type' => CredentialType::Api],
                ['username' => 'mikweb', 'secret' => 'change-me']
            );
        }
    }
}

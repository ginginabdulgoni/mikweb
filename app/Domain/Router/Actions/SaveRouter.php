<?php

declare(strict_types=1);

namespace App\Domain\Router\Actions;

use App\Domain\Router\Enums\CredentialType;
use App\Domain\Router\Models\Router;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a router row plus its API/SNMP credentials in a single
 * local DB transaction. Note: this only touches MikWeb's own database — it
 * never implies the device itself was reconfigured.
 */
class SaveRouter
{
    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public function create(int $organizationId, int $userId, array $data): Router
    {
        return DB::transaction(function () use ($organizationId, $userId, $data) {
            $host = $data['host'] ?? null;
            if ($host) {
                // If a soft-deleted router with this host exists in this organization,
                // permanently remove it so the new router can be registered with fresh configuration.
                Router::onlyTrashed()
                    ->where('organization_id', $organizationId)
                    ->where('host', $host)
                    ->forceDelete();
            }

            $router = new Router($this->routerAttributes($data));
            $router->organization_id = $organizationId;
            $router->created_by = $userId;
            $router->save();

            $this->syncCredentials($router, $data);

            return $router->load(['credentials', 'groups']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Router $router, array $data): Router
    {
        return DB::transaction(function () use ($router, $data) {
            $host = $data['host'] ?? null;
            if ($host && $host !== $router->host) {
                Router::onlyTrashed()
                    ->where('organization_id', $router->organization_id)
                    ->where('host', $host)
                    ->forceDelete();
            }

            $router->fill($this->routerAttributes($data))->save();
            $this->syncCredentials($router, $data);

            return $router->load(['credentials', 'groups']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function routerAttributes(array $data): array
    {
        return collect($data)->only([
            'name', 'host', 'preferred_transport', 'api_port', 'api_ssl_port', 'ssh_port',
            'use_tls', 'verify_tls', 'snmp_enabled', 'snmp_version', 'snmp_port',
            'site', 'isp', 'location', 'latitude', 'longitude', 'tags',
            'poll_interval_seconds', 'monitoring_enabled', 'notes',
        ])->toArray();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncCredentials(Router $router, array $data): void
    {
        $username = data_get($data, 'credential.username');
        $password = data_get($data, 'credential.password');

        if (filled($username) || filled($password)) {
            $credential = $router->credentials()->firstOrNew(['type' => CredentialType::Api]);

            if (filled($username)) {
                $credential->username = $username;
            }
            // Only overwrite the secret when a new one is supplied.
            if (filled($password)) {
                $credential->secret = $password;
            }
            $credential->save();
        }

        $community = data_get($data, 'snmp_community');
        if (filled($community)) {
            $router->credentials()->updateOrCreate(
                ['type' => CredentialType::Snmp],
                ['secret' => $community],
            );
        }
    }
}

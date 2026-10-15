<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Organization\Models\Organization;
use App\Domain\Router\Enums\CredentialType;
use App\Domain\Router\Enums\RouterStatus;
use App\Domain\Router\Enums\Transport;
use App\Domain\Router\Models\Router;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Router>
 */
class RouterFactory extends Factory
{
    protected $model = Router::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => 'RB-'.fake()->unique()->numberBetween(1000, 9999),
            'host' => fake()->unique()->ipv4(),
            'preferred_transport' => Transport::Api,
            'status' => RouterStatus::Unknown,
            'monitoring_enabled' => true,
        ];
    }

    public function withApiCredential(string $username = 'admin', string $secret = 'secret'): static
    {
        return $this->afterCreating(function (Router $router) use ($username, $secret) {
            $router->credentials()->create([
                'type' => CredentialType::Api,
                'username' => $username,
                'secret' => $secret,
            ]);
        });
    }
}

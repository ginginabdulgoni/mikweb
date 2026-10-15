<?php

declare(strict_types=1);

namespace Tests\Feature\RouterOS;

use App\Domain\Router\Enums\RouterStatus;
use App\Domain\Router\Models\Router;
use App\Services\RouterOS\Adapters\MockRouterOsClient;
use App\Services\RouterOS\ConnectionManager;
use App\Services\RouterOS\Exceptions\CircuitOpenException;
use App\Services\RouterOS\Support\CircuitBreaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnectionEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MockRouterOsClient::reset();
        config(['mikweb.routeros.driver' => 'mock']);
    }

    private function manager(): ConnectionManager
    {
        return app(ConnectionManager::class);
    }

    public function test_test_connection_succeeds_and_persists_inventory(): void
    {
        $router = Router::factory()->withApiCredential()->create();

        $result = $this->manager()->testConnection($router->fresh(['credentials']));

        $this->assertTrue($result->success);
        $this->assertArrayHasKey('identity', $result->info);

        $router->refresh();
        $this->assertSame(RouterStatus::Online, $router->status);
        $this->assertNotNull($router->routeros_version);
        $this->assertDatabaseHas('router_connection_logs', [
            'router_id' => $router->id,
            'event' => 'test',
            'success' => true,
        ]);
    }

    public function test_unreachable_host_fails_gracefully_and_marks_unreachable(): void
    {
        $router = Router::factory()->withApiCredential()->create();
        MockRouterOsClient::$unreachableHosts = [$router->host];

        $result = $this->manager()->testConnection($router->fresh(['credentials']));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('timed out', $result->message);
        $this->assertSame(RouterStatus::Unreachable, $router->fresh()->status);
    }

    public function test_missing_credentials_is_reported_not_thrown(): void
    {
        $router = Router::factory()->create(); // no credential

        $result = $this->manager()->testConnection($router->fresh(['credentials']));

        $this->assertFalse($result->success);
    }

    public function test_query_filters_rows(): void
    {
        $router = Router::factory()->withApiCredential()->create();

        $all = $this->manager()->query($router->fresh(['credentials']), '/interface/print');
        $this->assertCount(3, $all);

        $this->assertDatabaseHas('router_command_logs', [
            'router_id' => $router->id,
            'command' => '/interface/print',
            'success' => true,
        ]);
    }

    public function test_circuit_breaker_opens_after_threshold_and_fails_fast(): void
    {
        config([
            'mikweb.routeros.attempts' => 1,
            'mikweb.circuit_breaker.failure_threshold' => 3,
        ]);

        $router = Router::factory()->withApiCredential()->create();
        MockRouterOsClient::$unreachableHosts = [$router->host];

        $breaker = app(CircuitBreaker::class);

        // Three failures should trip the breaker.
        for ($i = 0; $i < 3; $i++) {
            try {
                $this->manager()->run($router->fresh(['credentials']), fn ($c) => $c->query('/system/identity/print'));
            } catch (\Throwable) {
                // expected
            }
        }

        $this->assertTrue($breaker->isOpen((int) $router->id));

        $this->expectException(CircuitOpenException::class);
        $this->manager()->run($router->fresh(['credentials']), fn ($c) => $c->query('/system/identity/print'));
    }
}

<?php

declare(strict_types=1);

namespace App\Services\RouterOS;

use App\Domain\Router\Enums\RouterStatus;
use App\Domain\Router\Models\Router;
use App\Domain\Router\Models\RouterCommandLog;
use App\Domain\Router\Models\RouterConnectionLog;
use App\Services\RouterOS\Contracts\RouterOsClient;
use App\Services\RouterOS\Exceptions\CircuitOpenException;
use App\Services\RouterOS\Exceptions\RouterOsConnectionException;
use App\Services\RouterOS\Exceptions\RouterOsException;
use App\Services\RouterOS\Support\CircuitBreaker;
use App\Services\RouterOS\Support\ConnectionTestResult;
use App\Support\SecretRedactor;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The single orchestration point for every RouterOS interaction. It applies
 * the circuit breaker, retry/backoff, per-router write locks, and records
 * connection + command logs. Controllers/jobs never touch a RouterOsClient
 * directly — they go through here so policy is enforced in one place.
 */
class ConnectionManager
{
    public function __construct(
        private readonly RouterOsClientFactory $factory,
        private readonly CircuitBreaker $breaker,
    ) {}

    /**
     * Read from a print menu, with full logging. Safe (no device mutation).
     *
     * @param  array<string, scalar>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function query(Router $router, string $command, array $filters = [], ?int $userId = null): array
    {
        return $this->runCommand($router, $command, $filters, $userId, write: false);
    }

    /**
     * Execute a write/action command under a per-router lock, with logging.
     *
     * @param  array<string, scalar>  $params
     * @return array<int, array<string, mixed>>
     */
    public function execute(Router $router, string $command, array $params = [], ?int $userId = null): array
    {
        return $this->withLock($router, fn () => $this->runCommand($router, $command, $params, $userId, write: true));
    }

    /**
     * Open a session, read identity + resources, and update the router's
     * stored status/inventory. Never throws — returns a structured result the
     * UI can render either way.
     */
    public function testConnection(Router $router): ConnectionTestResult
    {
        $start = microtime(true);

        try {
            $client = $this->connectedClient($router);

            $identity = $client->query('/system/identity/print')[0]['name'] ?? null;
            $resource = $client->query('/system/resource/print')[0] ?? [];

            $client->disconnect();
            $this->breaker->recordSuccess((int) $router->id);

            $durationMs = $this->elapsedMs($start);
            $info = [
                'identity' => $identity,
                'version' => $resource['version'] ?? null,
                'board_name' => $resource['board-name'] ?? null,
                'architecture' => $resource['architecture-name'] ?? null,
                'uptime' => $resource['uptime'] ?? null,
            ];

            $this->logConnection($router, 'test', true, $durationMs, 'Connection successful.', $info);
            $this->markOnline($router, $info);

            return new ConnectionTestResult(true, $durationMs, 'Connection successful.', array_filter($info, fn ($v) => $v !== null));
        } catch (Throwable $e) {
            $durationMs = $this->elapsedMs($start);
            $message = $e instanceof RouterOsException ? $e->getMessage() : 'Unexpected error while testing the connection.';

            $this->logConnection($router, 'test', false, $durationMs, $message);
            $this->markUnreachable($router, $message);

            return new ConnectionTestResult(false, $durationMs, $message);
        }
    }

    /**
     * Run arbitrary work with a short-lived connected client, applying the
     * circuit breaker and retry policy. Caller receives the RouterOsClient.
     *
     * @template T
     *
     * @param  callable(RouterOsClient): T  $callback
     * @return T
     */
    public function run(Router $router, callable $callback): mixed
    {
        $this->guardCircuit($router);

        $attempts = max(1, (int) config('mikweb.routeros.attempts', 2));
        $backoff = (int) config('mikweb.routeros.retry_backoff_ms', 250);
        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $client = $this->connectedClient($router);
                $result = $callback($client);
                $client->disconnect();
                $this->breaker->recordSuccess((int) $router->id);

                return $result;
            } catch (RouterOsConnectionException $e) {
                $lastError = $e;
                $this->breaker->recordFailure((int) $router->id);

                if ($attempt < $attempts) {
                    usleep($backoff * (2 ** ($attempt - 1)) * 1000);
                }
            }
        }

        $this->markUnreachable($router, $lastError?->getMessage() ?? 'Connection failed.');

        throw $lastError ?? new RouterOsConnectionException('Connection failed.');
    }

    private function connectedClient(Router $router): RouterOsClient
    {
        $client = $this->factory->for($router);
        $client->connect();

        return $client;
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<int, array<string, mixed>>
     */
    private function runCommand(Router $router, string $command, array $params, ?int $userId, bool $write): array
    {
        $start = microtime(true);

        try {
            $rows = $this->run($router, fn (RouterOsClient $client) => $write
                ? $client->execute($command, $params)
                : $client->query($command, $params));

            $this->logCommand($router, $command, $params, true, $this->elapsedMs($start), count($rows), null, $userId);

            return $rows;
        } catch (Throwable $e) {
            $message = $e instanceof RouterOsException ? $e->getMessage() : 'RouterOS command failed.';
            $this->logCommand($router, $command, $params, false, $this->elapsedMs($start), null, $message, $userId);

            throw $e;
        }
    }

    private function guardCircuit(Router $router): void
    {
        if ($this->breaker->isOpen((int) $router->id)) {
            throw new CircuitOpenException(
                'The router is temporarily marked unreachable after repeated failures. Try again shortly.'
            );
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withLock(Router $router, callable $callback): mixed
    {
        $store = config('mikweb.locks.store');
        $ttl = (int) config('mikweb.locks.ttl_seconds', 30);
        $wait = (int) config('mikweb.locks.wait_seconds', 10);

        $lock = Cache::store($store)->lock("mikweb:router:{$router->id}:write", $ttl);

        return $lock->block($wait, $callback);
    }

    private function markOnline(Router $router, array $info): void
    {
        $metadata = array_filter([
            'identity' => $info['identity'] ?? $router->identity,
            'routeros_version' => $info['version'] ?? $router->routeros_version,
            'board_name' => $info['board_name'] ?? $router->board_name,
            'architecture' => $info['architecture'] ?? $router->architecture,
        ], fn ($v) => $v !== null && $v !== '');

        $router->forceFill([
            'status' => RouterStatus::Online,
            'last_seen_at' => now(),
            'last_error' => null,
            ...$metadata,
        ])->save();
    }

    private function markUnreachable(Router $router, string $message): void
    {
        $router->forceFill([
            'status' => RouterStatus::Unreachable,
            'last_error' => mb_substr($message, 0, 1000),
        ])->save();
    }

    private function logConnection(Router $router, string $event, bool $success, int $durationMs, string $message, array $context = []): void
    {
        RouterConnectionLog::create([
            'router_id' => $router->id,
            'transport' => $router->preferred_transport->value,
            'event' => $event,
            'success' => $success,
            'duration_ms' => $durationMs,
            'message' => $message,
            'context' => $context === [] ? null : SecretRedactor::scrub($context),
            'created_at' => now(),
        ]);
    }

    private function logCommand(Router $router, string $command, array $params, bool $success, int $durationMs, ?int $resultCount, ?string $error, ?int $userId): void
    {
        RouterCommandLog::create([
            'router_id' => $router->id,
            'user_id' => $userId,
            'transport' => $router->preferred_transport->value,
            'command' => $command,
            'arguments' => $params === [] ? null : SecretRedactor::scrub($params),
            'success' => $success,
            'duration_ms' => $durationMs,
            'result_count' => $resultCount,
            'error' => $error,
            'created_at' => now(),
        ]);
    }

    private function elapsedMs(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\RouterOS;

use App\Domain\Router\Models\Router;

/**
 * Thin convenience layer over ConnectionManager for standard RouterOS menus
 * that follow the print/add/set/remove/enable/disable convention. Keeps module
 * controllers tiny: they declare a menu path and the field set, nothing more.
 *
 * All calls still flow through ConnectionManager, so the circuit breaker,
 * retry, per-router write lock, and logging apply uniformly.
 */
class ResourceClient
{
    public function __construct(private readonly ConnectionManager $connections) {}

    /**
     * @param  array<string, scalar>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(Router $router, string $menu, array $filters = [], ?int $userId = null): array
    {
        return $this->connections->query($router, $menu.'/print', $filters, $userId);
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<int, array<string, mixed>>
     */
    public function add(Router $router, string $menu, array $params, ?int $userId = null): array
    {
        return $this->connections->execute($router, $menu.'/add', $params, $userId);
    }

    /**
     * @param  array<string, scalar>  $params
     */
    public function set(Router $router, string $menu, string $id, array $params, ?int $userId = null): void
    {
        $this->connections->execute($router, $menu.'/set', ['.id' => $id, ...$params], $userId);
    }

    public function remove(Router $router, string $menu, string $id, ?int $userId = null): void
    {
        $this->connections->execute($router, $menu.'/remove', ['.id' => $id], $userId);
    }

    public function enable(Router $router, string $menu, string $id, ?int $userId = null): void
    {
        $this->connections->execute($router, $menu.'/enable', ['.id' => $id], $userId);
    }

    public function disable(Router $router, string $menu, string $id, ?int $userId = null): void
    {
        $this->connections->execute($router, $menu.'/disable', ['.id' => $id], $userId);
    }

    /** Arbitrary action command (e.g. reset-counters, monitor). */
    public function action(Router $router, string $command, array $params = [], ?int $userId = null): array
    {
        return $this->connections->execute($router, $command, $params, $userId);
    }
}

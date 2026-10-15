<?php

declare(strict_types=1);

namespace App\Domain\Router\Actions;

use App\Domain\Router\Enums\RouterStatus;
use App\Domain\Router\Models\Router;
use App\Services\RouterOS\ConnectionManager;
use App\Services\RouterOS\Contracts\RouterOsClient;

/**
 * Reads identity, resources, RouterBOARD, and installed packages from a device
 * and persists them to the router row + router_capabilities. RouterOS remains
 * the source of truth; this is cached inventory/metadata only.
 *
 * Capability detection makes feature gating honest: v6 vs v7, legacy "wireless"
 * vs modern "wifi", CAPsMAN, REST support — so the UI can hide what a given
 * device cannot do instead of guessing.
 */
class SyncRouterInfo
{
    public function __construct(private readonly ConnectionManager $connections) {}

    public function execute(Router $router): Router
    {
        [$identity, $resource, $routerboard, $packages] = $this->connections->run(
            $router,
            function (RouterOsClient $client): array {
                return [
                    $client->query('/system/identity/print')[0]['name'] ?? null,
                    $client->query('/system/resource/print')[0] ?? [],
                    $this->safe(fn () => $client->query('/system/routerboard/print')[0] ?? []),
                    $this->safe(fn () => $client->query('/system/package/print')),
                ];
            }
        );

        $version = (string) ($resource['version'] ?? '');
        $major = $this->majorVersion($version);
        $packageNames = $this->packageNames($packages ?? []);

        $router->forceFill([
            'status' => RouterStatus::Online,
            'last_seen_at' => now(),
            'last_error' => null,
            ...array_filter([
                'identity' => $identity,
                'routeros_version' => $version !== '' ? $version : null,
                'board_name' => $resource['board-name'] ?? null,
                'architecture' => $resource['architecture-name'] ?? null,
                'hardware_model' => $routerboard['model'] ?? ($resource['board-name'] ?? null),
                'serial_number' => $routerboard['serial-number'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
        ])->save();

        $router->capability()->updateOrCreate([], [
            'routeros_major' => $major,
            'supports_rest' => $major !== null && $major >= 7,
            'has_wifi_package' => $this->hasAnyPackage($packageNames, ['wifi-qcom', 'wifi-qcom-ac', 'wifiwave2', 'wifi']),
            'has_wireless_package' => $this->hasAnyPackage($packageNames, ['wireless']),
            'has_capsman' => $major !== null && ($major >= 7 || $this->hasAnyPackage($packageNames, ['wireless'])),
            'has_container' => $this->hasAnyPackage($packageNames, ['container']),
            'features' => [
                'rest_api' => $major !== null && $major >= 7,
                'queue_tree' => true,
                'ipv6' => $this->hasAnyPackage($packageNames, ['ipv6']) || $major >= 7,
            ],
            'packages' => $packageNames,
            'detected_at' => now(),
        ]);

        return $router->refresh();
    }

    private function majorVersion(string $version): ?int
    {
        return preg_match('/^(\d+)\./', $version, $m) ? (int) $m[1] : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $packages
     * @return array<int, string>
     */
    private function packageNames(array $packages): array
    {
        return array_values(array_filter(array_map(
            fn (array $p): string => (string) ($p['name'] ?? ''),
            $packages,
        )));
    }

    /**
     * @param  array<int, string>  $installed
     * @param  array<int, string>  $candidates
     */
    private function hasAnyPackage(array $installed, array $candidates): bool
    {
        return array_intersect($installed, $candidates) !== [];
    }

    /**
     * Optional reads (routerboard, packages) shouldn't fail the whole sync on
     * a CHR or stripped image that lacks them.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|array{}
     */
    private function safe(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (\Throwable) {
            return [];
        }
    }
}

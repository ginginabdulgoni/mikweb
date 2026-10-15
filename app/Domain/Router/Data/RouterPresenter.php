<?php

declare(strict_types=1);

namespace App\Domain\Router\Data;

use App\Domain\Router\Enums\CredentialType;
use App\Domain\Router\Enums\RouterStatus;
use App\Domain\Router\Models\Router;

/**
 * Shapes Router models for the UI/API. Credentials are never included — only
 * presence/username metadata. This is the serialization boundary that keeps
 * secrets out of Inertia props and JSON responses.
 */
final class RouterPresenter
{
    /** @return array<string, mixed> */
    public static function summary(Router $router): array
    {
        $status = $router->status instanceof RouterStatus ? $router->status : RouterStatus::Unknown;

        return [
            'id' => $router->id,
            'name' => $router->name,
            'host' => $router->host,
            'identity' => $router->identity,
            'site' => $router->site,
            'isp' => $router->isp,
            'status' => $status->value,
            'status_label' => $status->label(),
            'status_color' => $status->color(),
            'routeros_version' => $router->routeros_version,
            'hardware_model' => $router->hardware_model,
            'last_seen_at' => $router->last_seen_at?->toIso8601String(),
            'monitoring_enabled' => (bool) $router->monitoring_enabled,
            'tags' => $router->tags ?? [],
            'groups' => $router->relationLoaded('groups')
                ? $router->groups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name])->values()
                : [],
        ];
    }

    /**
     * Compact header used by every per-router module page to render the
     * router's identity + the module sub-navigation. `modules` reflects
     * detected capabilities so the UI can gate what the device supports.
     *
     * @return array<string, mixed>
     */
    public static function header(Router $router): array
    {
        $status = $router->status instanceof RouterStatus ? $router->status : RouterStatus::Unknown;
        $cap = $router->relationLoaded('capability') ? $router->capability : $router->capability()->first();

        return [
            'id' => $router->id,
            'name' => $router->name,
            'host' => $router->host,
            'identity' => $router->identity,
            'status' => $status->value,
            'status_label' => $status->label(),
            'status_color' => $status->color(),
            'routeros_major' => $cap?->routeros_major,
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(Router $router): array
    {
        $apiCredential = $router->credentials->firstWhere('type', CredentialType::Api->value);

        return [
            ...self::summary($router),
            'preferred_transport' => $router->preferred_transport->value,
            'api_port' => $router->api_port,
            'api_ssl_port' => $router->api_ssl_port,
            'ssh_port' => $router->ssh_port,
            'use_tls' => (bool) $router->use_tls,
            'verify_tls' => (bool) $router->verify_tls,
            'snmp_enabled' => (bool) $router->snmp_enabled,
            'snmp_version' => $router->snmp_version,
            'snmp_port' => $router->snmp_port,
            'location' => $router->location,
            'latitude' => $router->latitude,
            'longitude' => $router->longitude,
            'serial_number' => $router->serial_number,
            'architecture' => $router->architecture,
            'board_name' => $router->board_name,
            'poll_interval_seconds' => $router->poll_interval_seconds,
            'notes' => $router->notes,
            'last_error' => $router->last_error,
            'credentials' => [
                // Metadata only — never the secret itself.
                'api' => [
                    'configured' => $apiCredential !== null,
                    'username' => $apiCredential?->username,
                ],
            ],
            'capability' => $router->relationLoaded('capability') && $router->capability
                ? [
                    'routeros_major' => $router->capability->routeros_major,
                    'supports_rest' => $router->capability->supports_rest,
                    'has_wifi_package' => $router->capability->has_wifi_package,
                    'has_wireless_package' => $router->capability->has_wireless_package,
                    'has_capsman' => $router->capability->has_capsman,
                ]
                : null,
            'created_at' => $router->created_at?->toIso8601String(),
        ];
    }
}

<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | RouterOS connection engine
    |--------------------------------------------------------------------------
    |
    | Global fallbacks for the RouterOS connection engine. Per-router values
    | stored in the database always take precedence over these. The `driver`
    | selects which RouterOsClient implementation the container resolves:
    |   - "library": real RouterOS API protocol via evilfreelancer/routeros-api-php
    |   - "mock":    in-memory fake used for local dev / tests (no device needed)
    |
    */

    'routeros' => [
        'driver' => env('MIKWEB_ROUTEROS_DRIVER', 'library'),

        // Seconds allowed for a single RouterOS API call before timing out.
        'timeout' => (int) env('MIKWEB_API_TIMEOUT', 8),

        // Seconds to wait while establishing the TCP/TLS socket.
        'connect_timeout' => (int) env('MIKWEB_API_CONNECT_TIMEOUT', 5),

        // How many times a transient failure is retried (total tries = attempts).
        'attempts' => (int) env('MIKWEB_API_ATTEMPTS', 2),

        // Base backoff in milliseconds between retries (exponential).
        'retry_backoff_ms' => (int) env('MIKWEB_API_RETRY_BACKOFF_MS', 250),

        'ports' => [
            'api' => (int) env('MIKWEB_API_PORT', 8728),
            'api_ssl' => (int) env('MIKWEB_API_SSL_PORT', 8729),
            'ssh' => (int) env('MIKWEB_SSH_PORT', 22),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit breaker
    |--------------------------------------------------------------------------
    |
    | Protects both MikWeb and the devices: after N consecutive failures the
    | circuit for a router "opens" and calls fail fast for a cooldown window
    | instead of hammering an unreachable device.
    |
    */

    'circuit_breaker' => [
        'enabled' => (bool) env('MIKWEB_CIRCUIT_ENABLED', true),
        'failure_threshold' => (int) env('MIKWEB_CIRCUIT_FAILURE_THRESHOLD', 5),
        'cooldown_seconds' => (int) env('MIKWEB_CIRCUIT_COOLDOWN', 30),
        'cache_store' => env('MIKWEB_CIRCUIT_CACHE_STORE', 'redis'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-router concurrency lock
    |--------------------------------------------------------------------------
    |
    | Configuration-changing operations acquire a short-lived lock keyed by
    | router so two writers never race against the same device.
    |
    */

    'locks' => [
        'store' => env('MIKWEB_LOCK_STORE', 'redis'),
        'ttl_seconds' => (int) env('MIKWEB_LOCK_TTL', 30),
        'wait_seconds' => (int) env('MIKWEB_LOCK_WAIT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | SNMP
    |--------------------------------------------------------------------------
    */

    'snmp' => [
        'timeout' => (int) env('MIKWEB_SNMP_TIMEOUT', 2_000_000), // microseconds
        'retries' => (int) env('MIKWEB_SNMP_RETRIES', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Secret redaction
    |--------------------------------------------------------------------------
    |
    | Keys whose values are stripped before anything is written to a log,
    | audit entry, or connection-log context payload.
    |
    */

    'redacted_keys' => [
        'password', 'passwd', 'secret', 'community', 'private-key', 'private_key',
        'token', 'api_key', 'authorization', 'snmp_auth_password', 'snmp_priv_password',
    ],
];

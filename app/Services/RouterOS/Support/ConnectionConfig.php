<?php

declare(strict_types=1);

namespace App\Services\RouterOS\Support;

use App\Domain\Router\Enums\CredentialType;
use App\Domain\Router\Enums\Transport;
use App\Domain\Router\Models\Router;
use App\Services\RouterOS\Exceptions\RouterOsConnectionException;

/**
 * Immutable, fully-resolved parameters for one RouterOS session. Built from a
 * Router + its (decrypted) API credential. This is the ONLY place plaintext
 * secrets live, and the object is never logged or serialized.
 */
final class ConnectionConfig
{
    public function __construct(
        public readonly int $routerId,
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        public readonly string $password,
        public readonly Transport $transport,
        public readonly bool $ssl,
        public readonly bool $verifyTls,
        public readonly int $timeout,
        public readonly int $connectTimeout,
        public readonly int $attempts,
    ) {}

    public static function forRouter(Router $router): self
    {
        $credential = $router->credentialFor(CredentialType::Api);

        if ($credential === null || blank($credential->username)) {
            throw new RouterOsConnectionException(
                "Router #{$router->id} has no API credentials configured."
            );
        }

        $transport = $router->preferred_transport instanceof Transport
            ? $router->preferred_transport
            : Transport::from((string) $router->preferred_transport);

        // SSH/REST are not handled by the API client; fall back to plain API
        // for the API-protocol session while keeping the device preference.
        $ssl = $transport === Transport::ApiSsl || $router->use_tls;

        return new self(
            routerId: (int) $router->id,
            host: (string) $router->host,
            port: $ssl ? (int) $router->api_ssl_port : (int) $router->api_port,
            username: (string) $credential->username,
            password: (string) ($credential->secret ?? ''),
            transport: $transport,
            ssl: $ssl,
            verifyTls: (bool) $router->verify_tls,
            timeout: (int) config('mikweb.routeros.timeout', 8),
            connectTimeout: (int) config('mikweb.routeros.connect_timeout', 5),
            attempts: max(1, (int) config('mikweb.routeros.attempts', 2)),
        );
    }

    /**
     * Build the config array consumed by evilfreelancer/routeros-api-php.
     *
     * @return array<string, mixed>
     */
    public function toLibraryConfig(): array
    {
        $config = [
            'host' => $this->host,
            'user' => $this->username,
            'pass' => $this->password,
            'port' => $this->port,
            'ssl' => $this->ssl,
            'timeout' => $this->connectTimeout,
            'socket_timeout' => $this->timeout,
            'attempts' => $this->attempts,
            'delay' => 1,
            // PHP 8.4 stream read-timeout interop: let us classify the error
            // ourselves instead of the library throwing on empty reads.
            'throw_timeout_exception' => true,
        ];

        if ($this->ssl) {
            $config['ssl_options'] = [
                'verify_peer' => $this->verifyTls,
                'verify_peer_name' => $this->verifyTls,
                'allow_self_signed' => ! $this->verifyTls,
                // RouterOS defaults to anonymous-DH ciphers unless a certificate
                // is installed; keep ADH available for the no-cert case.
                'ciphers' => $this->verifyTls ? 'DEFAULT' : 'ADH:ALL@SECLEVEL=0',
            ];
        }

        return $config;
    }
}

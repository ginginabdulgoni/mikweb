<?php

declare(strict_types=1);

namespace App\Services\SNMP;

use App\Domain\Router\Enums\CredentialType;
use App\Domain\Router\Models\Router;
use App\Services\RouterOS\Exceptions\RouterOsConnectionException;

/**
 * Lightweight SNMP reader for monitoring metrics that are cheaper to poll via
 * SNMP than the API (uptime, interface counters). Uses PHP's ext-snmp. SNMP is
 * strictly read-only here; configuration always goes through the API/SSH paths.
 */
class SnmpService
{
    // Common MikroTik / standard MIB OIDs.
    public const OID_UPTIME = '1.3.6.1.2.1.1.3.0';

    public const OID_SYSNAME = '1.3.6.1.2.1.1.5.0';

    public const OID_SYSDESCR = '1.3.6.1.2.1.1.1.0';

    public function isAvailable(): bool
    {
        return extension_loaded('snmp');
    }

    /**
     * Fetch a single OID as a string. Returns null on timeout/unreachable.
     */
    public function get(Router $router, string $oid): ?string
    {
        $session = $this->session($router);

        $previous = set_error_handler(static fn (): bool => true); // swallow SNMP warnings

        try {
            $value = $session->get($oid);
        } catch (\Throwable) {
            return null;
        } finally {
            restore_error_handler();
        }

        return $value === false ? null : $this->clean((string) $value);
    }

    /**
     * Return a small health snapshot via SNMP.
     *
     * @return array{reachable: bool, uptime_ticks: ?int, sys_name: ?string}
     */
    public function health(Router $router): array
    {
        $uptime = $this->get($router, self::OID_UPTIME);

        return [
            'reachable' => $uptime !== null,
            'uptime_ticks' => $uptime !== null ? (int) filter_var($uptime, FILTER_SANITIZE_NUMBER_INT) : null,
            'sys_name' => $this->get($router, self::OID_SYSNAME),
        ];
    }

    private function session(Router $router): \SNMP
    {
        if (! $this->isAvailable()) {
            throw new RouterOsConnectionException('The PHP SNMP extension is not installed on the server.');
        }

        if (! $router->snmp_enabled) {
            throw new RouterOsConnectionException("SNMP is not enabled for router #{$router->id}.");
        }

        $credential = $router->credentialFor(CredentialType::Snmp);
        $version = $router->snmp_version === 'v3' ? \SNMP::VERSION_3 : \SNMP::VERSION_2c;
        $hostspec = $router->host.':'.$router->snmp_port;

        $community = $version === \SNMP::VERSION_2c
            ? (string) ($credential?->secret ?? 'public')
            : '';

        $session = new \SNMP($version, $hostspec, $community, (int) config('mikweb.snmp.timeout', 2_000_000), (int) config('mikweb.snmp.retries', 1));
        $session->valueretrieval = SNMP_VALUE_PLAIN;

        if ($version === \SNMP::VERSION_3 && $credential !== null) {
            $session->setSecurity(
                $credential->snmp_security_level ?: 'authPriv',
                $credential->snmp_auth_protocol ?: 'SHA',
                (string) ($credential->snmp_auth_password ?? ''),
                $credential->snmp_priv_protocol ?: 'AES',
                (string) ($credential->snmp_priv_password ?? ''),
                (string) ($credential->username ?? ''),
            );
        }

        return $session;
    }

    private function clean(string $value): string
    {
        // ext-snmp often wraps values like: STRING: "MikroTik"
        if (preg_match('/^[A-Z0-9-]+:\s*"?(.*?)"?$/', $value, $m)) {
            return $m[1];
        }

        return trim($value, "\" \t\n\r\0\x0B");
    }
}

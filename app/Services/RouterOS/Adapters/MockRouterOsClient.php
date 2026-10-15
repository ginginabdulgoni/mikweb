<?php

declare(strict_types=1);

namespace App\Services\RouterOS\Adapters;

use App\Services\RouterOS\Contracts\RouterOsClient;
use App\Services\RouterOS\Exceptions\RouterOsCommandException;
use App\Services\RouterOS\Exceptions\RouterOsConnectionException;
use App\Services\RouterOS\Support\ConnectionConfig;

/**
 * In-memory, STATEFUL RouterOS stand-in for local development and tests. It
 * maintains a per-menu store so add/set/remove/enable/disable actually mutate
 * state within a process — letting the full CRUD UI and feature tests run with
 * no device. Reset between tests via reset().
 *
 * Static read menus (/system/resource, …) return realistic fixed shapes.
 */
class MockRouterOsClient implements RouterOsClient
{
    /** @var array<int, string> hosts that should fail to connect */
    public static array $unreachableHosts = [];

    /** @var array<int, string> hosts that reject auth */
    public static array $badCredentialHosts = [];

    /** @var array<string, array<int, array<string, mixed>>> live mutable store keyed by menu */
    private static array $store = [];

    private static bool $seeded = false;

    // Starts past the seeded single-hex ids (*1..*3) so generated ids never collide.
    private static int $idSeq = 4096;

    private ?ConnectionConfig $config = null;

    private bool $connected = false;

    private const WRITE_ACTIONS = ['add', 'set', 'remove', 'enable', 'disable', 'make-static', 'reset-counters'];

    public static function reset(): void
    {
        self::$unreachableHosts = [];
        self::$badCredentialHosts = [];
        self::$store = [];
        self::$seeded = false;
        self::$idSeq = 4096;
    }

    public function configure(ConnectionConfig $config): static
    {
        $this->config = $config;

        return $this;
    }

    public function connect(): void
    {
        $host = $this->config?->host ?? '';

        if (in_array($host, self::$unreachableHosts, true)) {
            throw new RouterOsConnectionException(
                'Connection to the router timed out (host unreachable or API port filtered).'
            );
        }
        if (in_array($host, self::$badCredentialHosts, true)) {
            throw new RouterOsConnectionException(
                'Authentication rejected by the router (check username/password).'
            );
        }

        $this->seed();
        $this->connected = true;
    }

    public function disconnect(): void
    {
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function query(string $command, array $filters = []): array
    {
        $this->ensureConnected();

        [$menu, $action] = $this->split($command);

        // Static system reads.
        $static = $this->staticRead($command);
        if ($static !== null) {
            return $static;
        }

        if (! array_key_exists($menu, self::$store)) {
            throw new RouterOsCommandException("no such command prefix ({$command})");
        }

        $rows = array_values(self::$store[$menu]);

        foreach ($filters as $key => $value) {
            $needle = $this->stringify($value);
            $rows = array_values(array_filter($rows, fn (array $r): bool => (string) ($r[$key] ?? '') === $needle));
        }

        return $rows;
    }

    public function execute(string $command, array $params = []): array
    {
        $this->ensureConnected();

        // Diagnostic tools run via execute() (they use "=" attributes).
        $tool = $this->toolResponse($command, $params);
        if ($tool !== null) {
            return $tool;
        }

        if ($command === '/system/reboot') {
            return [];
        }

        if ($command === '/system/backup/save') {
            $name = (string) ($params['name'] ?? ('backup-'.date('Ymd-His')));
            if (! str_ends_with($name, '.backup')) {
                $name .= '.backup';
            }
            $id = '*'.strtoupper(dechex(self::$idSeq++));
            self::$store['/file'][] = [
                '.id' => $id,
                'name' => $name,
                'type' => 'backup',
                'size' => '1048576',
                'creation-time' => date('M/d/Y H:i:s'),
            ];

            return [];
        }

        [$menu, $action] = $this->split($command);

        if (! in_array($action, self::WRITE_ACTIONS, true)) {
            // Unmodelled action — behave like a no-op success.
            return [];
        }

        self::$store[$menu] ??= [];

        return match ($action) {
            'add' => $this->doAdd($menu, $params),
            'set' => $this->doSet($menu, $params),
            'remove' => $this->doRemove($menu, $params),
            'enable' => $this->doToggle($menu, $params, disabled: false),
            'disable' => $this->doToggle($menu, $params, disabled: true),
            'make-static' => $this->doMakeStatic($menu, $params),
            'reset-counters' => $this->doResetCounters($menu, $params),
            default => [],
        };
    }

    // ── mutations ────────────────────────────────────────────────────

    private function doAdd(string $menu, array $params): array
    {
        $id = '*'.strtoupper(dechex(self::$idSeq++));
        $row = array_merge(['.id' => $id, 'disabled' => 'false'], $this->stringifyAll($params));
        $row['.id'] = $id;
        self::$store[$menu][] = $row;

        return [['.id' => $id, 'ret' => $id]];
    }

    private function doSet(string $menu, array $params): array
    {
        $id = (string) ($params['.id'] ?? '');
        $index = $id !== '' ? $this->indexOf($menu, $id) : 0;
        if ($menu === '/system/identity' || $menu === '/ppp/aaa') {
            self::$store[$menu] = [array_merge(self::$store[$menu][0] ?? ['.id' => '*1'], $this->stringifyAll($params))];

            return [];
        }
        if ($index === null || ! isset(self::$store[$menu][$index])) {
            throw new RouterOsCommandException('no such item (.id '.$id.')');
        }
        unset($params['.id']);
        self::$store[$menu][$index] = array_merge(self::$store[$menu][$index], $this->stringifyAll($params));

        return [];
    }

    private function doMakeStatic(string $menu, array $params): array
    {
        foreach (explode(',', (string) ($params['.id'] ?? '')) as $id) {
            $index = $this->indexOf($menu, trim($id));
            if ($index !== null) {
                self::$store[$menu][$index]['dynamic'] = 'false';
            }
        }

        return [];
    }

    private function doRemove(string $menu, array $params): array
    {
        foreach (explode(',', (string) ($params['.id'] ?? '')) as $id) {
            $index = $this->indexOf($menu, trim($id));
            if ($index !== null) {
                unset(self::$store[$menu][$index]);
            }
        }
        self::$store[$menu] = array_values(self::$store[$menu]);

        return [];
    }

    private function doToggle(string $menu, array $params, bool $disabled): array
    {
        foreach (explode(',', (string) ($params['.id'] ?? '')) as $id) {
            $index = $this->indexOf($menu, trim($id));
            if ($index !== null) {
                self::$store[$menu][$index]['disabled'] = $disabled ? 'true' : 'false';
            }
        }

        return [];
    }

    private function doResetCounters(string $menu, array $params): array
    {
        $id = (string) ($params['.id'] ?? $params['numbers'] ?? '');
        if ($id !== '') {
            foreach (explode(',', $id) as $singleId) {
                $index = $this->indexOf($menu, trim($singleId));
                if ($index !== null) {
                    self::$store[$menu][$index]['bytes'] = '0/0';
                    self::$store[$menu][$index]['packets'] = '0/0';
                }
            }
        } else {
            foreach (self::$store[$menu] ?? [] as $index => $_) {
                self::$store[$menu][$index]['bytes'] = '0/0';
                self::$store[$menu][$index]['packets'] = '0/0';
            }
        }

        return [];
    }

    private function indexOf(string $menu, string $id): ?int
    {
        foreach (self::$store[$menu] ?? [] as $i => $row) {
            if (($row['.id'] ?? null) === $id) {
                return $i;
            }
        }

        return null;
    }

    // ── helpers ──────────────────────────────────────────────────────

    /** @return array{0:string,1:string} [menu, action] */
    private function split(string $command): array
    {
        $pos = strrpos($command, '/');
        if ($pos === false || $pos === 0) {
            return [$command, ''];
        }

        return [substr($command, 0, $pos), substr($command, $pos + 1)];
    }

    private function ensureConnected(): void
    {
        if (! $this->connected) {
            $this->connect();
        }
    }

    private function stringify(mixed $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }

    /** @param array<string,mixed> $params @return array<string,string> */
    private function stringifyAll(array $params): array
    {
        return array_map(fn ($v) => $this->stringify($v), $params);
    }

    /**
     * Synthetic ping/traceroute output.
     *
     * @param  array<string, mixed>  $params
     * @return array<int, array<string, mixed>>|null
     */
    private function toolResponse(string $command, array $params): ?array
    {
        $addr = (string) ($params['address'] ?? '8.8.8.8');

        if ($command === '/ping') {
            $count = max(1, (int) ($params['count'] ?? 4));

            return array_map(fn (int $seq): array => [
                'seq' => (string) $seq,
                'host' => $addr,
                'size' => '56',
                'ttl' => '56',
                'time' => (10 + $seq).'ms',
                'sent' => (string) ($seq + 1),
                'received' => (string) ($seq + 1),
                'packet-loss' => '0',
            ], range(0, $count - 1));
        }

        if ($command === '/export') {
            return [['ret' => "# MikWeb mock export\n/system identity set name=MikWeb-Mock\n/ip address add address=192.168.88.1/24 interface=bridge1\n/ip firewall filter add chain=input action=accept connection-state=established,related\n"]];
        }

        if ($command === '/tool/traceroute') {
            return [
                ['address' => '192.168.88.1', 'hop' => '1', 'time' => '1ms'],
                ['address' => '10.0.0.1', 'hop' => '2', 'time' => '8ms'],
                ['address' => $addr, 'hop' => '3', 'time' => '18ms'],
            ];
        }

        if ($command === '/interface/monitor-traffic') {
            $iface = (string) ($params['interface'] ?? 'ether1');
            $rx = rand(2500000, 15000000);
            $tx = rand(800000, 5000000);

            return [
                [
                    'name' => $iface,
                    'rx-bits-per-second' => (string) $rx,
                    'tx-bits-per-second' => (string) $tx,
                    'rx-packets-per-second' => (string) (int) ($rx / 8 / 1500),
                    'tx-packets-per-second' => (string) (int) ($tx / 8 / 1500),
                ],
            ];
        }

        if ($command === '/tool/torch') {
            $iface = (string) ($params['interface'] ?? 'ether1');

            return [
                [
                    'src-address' => '192.168.88.105',
                    'dst-address' => '142.250.190.46',
                    'protocol' => 'tcp',
                    'src-port' => '54321',
                    'dst-port' => '443',
                    'tx-rate' => '124000',
                    'rx-rate' => '8500000',
                    'packets' => '1420',
                ],
                [
                    'src-address' => '192.168.88.100',
                    'dst-address' => '104.244.42.1',
                    'protocol' => 'udp',
                    'src-port' => '44123',
                    'dst-port' => '443',
                    'tx-rate' => '48000',
                    'rx-rate' => '1200000',
                    'packets' => '350',
                ],
                [
                    'src-address' => '192.168.88.10',
                    'dst-address' => '8.8.8.8',
                    'protocol' => 'icmp',
                    'src-port' => '',
                    'dst-port' => '',
                    'tx-rate' => '640',
                    'rx-rate' => '640',
                    'packets' => '12',
                ],
            ];
        }

        return null;
    }

    /** @return array<int, array<string, mixed>>|null */
    private function staticRead(string $command): ?array
    {
        return match ($command) {
            '/system/identity', '/system/identity/print' => self::$store['/system/identity'] ?? [['name' => 'MikWeb-Mock-'.($this->config?->routerId ?? 0)]],
            '/system/resource/print' => [[
                'uptime' => '3w2d4h15m', 'version' => '7.15.3 (stable)',
                'free-memory' => '402653184', 'total-memory' => '536870912',
                'cpu' => 'ARM', 'cpu-count' => '4', 'cpu-load' => '7',
                'architecture-name' => 'arm', 'board-name' => 'RB5009UG+S+', 'platform' => 'MikroTik',
            ]],
            '/system/routerboard/print' => [[
                'routerboard' => 'true', 'model' => 'RB5009UG+S+',
                'serial-number' => 'HEX0MOCK1234', 'current-firmware' => '7.15.3',
            ]],
            '/system/package/print' => [
                ['name' => 'routeros', 'version' => '7.15.3', 'disabled' => 'false'],
                ['name' => 'wifi-qcom', 'version' => '7.15.3', 'disabled' => 'false'],
            ],
            default => null,
        };
    }

    private function seed(): void
    {
        if (self::$seeded) {
            return;
        }
        self::$seeded = true;

        self::$store['/interface'] = [
            ['.id' => '*1', 'name' => 'ether1', 'type' => 'ether', 'running' => 'true', 'disabled' => 'false', 'rx-byte' => '184273645', 'tx-byte' => '92847362', 'mac-address' => 'DC:2C:6E:00:00:01', 'mtu' => '1500'],
            ['.id' => '*2', 'name' => 'ether2', 'type' => 'ether', 'running' => 'true', 'disabled' => 'false', 'rx-byte' => '12847362', 'tx-byte' => '8472615', 'mac-address' => 'DC:2C:6E:00:00:02', 'mtu' => '1500'],
            ['.id' => '*3', 'name' => 'bridge1', 'type' => 'bridge', 'running' => 'true', 'disabled' => 'false', 'rx-byte' => '0', 'tx-byte' => '0', 'mac-address' => 'DC:2C:6E:00:00:03', 'mtu' => '1500'],
        ];

        self::$store['/ip/address'] = [
            ['.id' => '*1', 'address' => '192.168.88.1/24', 'network' => '192.168.88.0', 'interface' => 'bridge1', 'disabled' => 'false', 'dynamic' => 'false'],
        ];

        self::$store['/ppp/secret'] = [
            ['.id' => '*1', 'name' => 'budi', 'service' => 'pppoe', 'profile' => 'default', 'disabled' => 'false', 'local-address' => '10.0.0.1', 'remote-address' => '10.0.0.50'],
            ['.id' => '*2', 'name' => 'siti', 'service' => 'pppoe', 'profile' => 'default', 'disabled' => 'true', 'local-address' => '', 'remote-address' => ''],
        ];

        self::$store['/ppp/active'] = [
            ['.id' => '*1', 'name' => 'budi', 'service' => 'pppoe', 'address' => '10.0.0.50', 'uptime' => '2h14m', 'caller-id' => 'DC:2C:6E:AA:BB:CC'],
        ];

        self::$store['/ppp/profile'] = [
            ['.id' => '*1', 'name' => 'default', 'local-address' => '10.0.0.1', 'remote-address' => '*1', 'rate-limit' => '10M/10M', 'dns-server' => '8.8.8.8', 'only-one' => 'default'],
            ['.id' => '*2', 'name' => 'default-encryption', 'local-address' => '10.0.0.1', 'remote-address' => 'pool1', 'rate-limit' => '20M/20M', 'dns-server' => '1.1.1.1', 'only-one' => 'yes'],
        ];

        self::$store['/interface/pppoe-server/server'] = [
            ['.id' => '*1', 'service-name' => 'pppoe-in1', 'interface' => 'ether1', 'default-profile' => 'default', 'disabled' => 'false', 'one-session-per-host' => 'true', 'max-mtu' => '1480', 'max-mru' => '1480'],
        ];

        self::$store['/ppp/aaa'] = [
            ['.id' => '*1', 'use-radius' => 'no', 'accounting' => 'yes', 'interim-update' => '0s'],
        ];

        self::$store['/ip/hotspot'] = [
            ['.id' => '*1', 'name' => 'hotspot1', 'interface' => 'bridge1', 'profile' => 'hsprof1', 'address-pool' => 'hs-pool-1', 'idle-timeout' => '5m', 'disabled' => 'false'],
        ];

        self::$store['/ip/hotspot/profile'] = [
            ['.id' => '*1', 'name' => 'hsprof1', 'hotspot-address' => '192.168.88.1', 'dns-name' => 'wifi.login', 'html-directory' => 'hotspot', 'login-by' => 'http-chap,cookie'],
        ];

        self::$store['/ip/hotspot/user/profile'] = [
            ['.id' => '*1', 'name' => 'default', 'shared-users' => '1', 'rate-limit' => '', 'status-autorefresh' => '1m'],
            ['.id' => '*2', 'name' => 'paket-1jam', 'shared-users' => '1', 'rate-limit' => '5M/5M', 'session-timeout' => '1h', 'status-autorefresh' => '1m'],
            ['.id' => '*3', 'name' => 'paket-vip', 'shared-users' => '2', 'rate-limit' => '20M/20M', 'session-timeout' => '24h', 'status-autorefresh' => '1m'],
        ];

        self::$store['/ip/hotspot/user'] = [
            ['.id' => '*1', 'name' => 'admin', 'profile' => 'default', 'server' => 'all', 'disabled' => 'false', 'uptime' => '1h20m', 'bytes-in' => '1425890', 'bytes-out' => '8492048', 'comment' => 'Default admin'],
            ['.id' => '*2', 'name' => 'vc-1001', 'profile' => 'paket-1jam', 'server' => 'hotspot1', 'disabled' => 'false', 'limit-uptime' => '1h', 'uptime' => '25m10s', 'bytes-in' => '512000', 'bytes-out' => '2048000', 'comment' => 'Voucher 1 Jam'],
            ['.id' => '*3', 'name' => 'vc-1002', 'profile' => 'paket-1jam', 'server' => 'hotspot1', 'disabled' => 'true', 'limit-uptime' => '1h', 'comment' => 'Voucher Expired'],
            ['.id' => '*4', 'name' => 'tamu-vip', 'profile' => 'paket-vip', 'server' => 'hotspot1', 'disabled' => 'false', 'comment' => 'Tamu Ruang VIP'],
        ];

        self::$store['/ip/hotspot/active'] = [
            ['.id' => '*1', 'user' => 'vc-1001', 'address' => '192.168.88.105', 'mac-address' => 'BC:D0:74:11:22:33', 'uptime' => '25m10s', 'bytes-in' => '512000', 'bytes-out' => '2048000', 'time-left' => '34m50s', 'server' => 'hotspot1'],
        ];

        self::$store['/ip/hotspot/ip-binding'] = [
            ['.id' => '*1', 'mac-address' => '00:1A:2B:3C:4D:5E', 'address' => '192.168.88.200', 'to-address' => '192.168.88.200', 'server' => 'hotspot1', 'type' => 'bypassed', 'disabled' => 'false', 'comment' => 'Smart TV Lobby'],
        ];

        self::$store['/ip/hotspot/host'] = [
            ['.id' => '*1', 'mac-address' => 'BC:D0:74:11:22:33', 'address' => '192.168.88.105', 'to-address' => '192.168.88.105', 'server' => 'hotspot1', 'authorized' => 'true', 'bypassed' => 'false'],
            ['.id' => '*2', 'mac-address' => '00:1A:2B:3C:4D:5E', 'address' => '192.168.88.200', 'to-address' => '192.168.88.200', 'server' => 'hotspot1', 'authorized' => 'false', 'bypassed' => 'true'],
        ];

        self::$store['/ip/firewall/filter'] = [
            ['.id' => '*1', 'chain' => 'input', 'action' => 'accept', 'connection-state' => 'established,related', 'disabled' => 'false'],
            ['.id' => '*2', 'chain' => 'input', 'action' => 'drop', 'in-interface' => 'ether1', 'disabled' => 'false'],
        ];

        self::$store['/ip/route'] = [
            ['.id' => '*1', 'dst-address' => '0.0.0.0/0', 'gateway' => '192.168.88.254', 'distance' => '1', 'active' => 'true', 'static' => 'true', 'disabled' => 'false'],
        ];

        self::$store['/log'] = [
            ['.id' => '*1', 'time' => 'may/20 09:00:01', 'topics' => 'system,info', 'message' => 'router rebooted'],
            ['.id' => '*2', 'time' => 'may/20 09:01:14', 'topics' => 'pppoe,info', 'message' => 'budi logged in'],
            ['.id' => '*3', 'time' => 'may/20 09:05:42', 'topics' => 'firewall,info', 'message' => 'input: drop from ether1'],
        ];

        self::$store['/system/script'] = [
            ['.id' => '*1', 'name' => 'daily-backup', 'owner' => 'admin', 'run-count' => '12', 'source' => '/system backup save name=auto'],
        ];

        self::$store['/system/identity'] = [
            ['.id' => '*1', 'name' => 'MikWeb-Mock'],
        ];

        self::$store['/queue/simple'] = [
            ['.id' => '*1', 'name' => 'queue-budi', 'target' => '10.0.0.50/32', 'max-limit' => '10M/10M', 'burst-limit' => '15M/15M', 'priority' => '8/8', 'bytes' => '18492048/9284726', 'disabled' => 'false', 'comment' => 'Budi Client Limit'],
            ['.id' => '*2', 'name' => 'queue-hotspot', 'target' => '192.168.88.0/24', 'max-limit' => '50M/50M', 'burst-limit' => '0/0', 'priority' => '8/8', 'bytes' => '98412495/43829104', 'disabled' => 'false', 'comment' => 'Hotspot Subnet'],
            ['.id' => '*3', 'name' => 'queue-tamu', 'target' => '192.168.88.200/32', 'max-limit' => '5M/5M', 'burst-limit' => '0/0', 'priority' => '8/8', 'bytes' => '420100/120400', 'disabled' => 'true', 'comment' => 'Tamu Room'],
        ];

        self::$store['/queue/tree'] = [
            ['.id' => '*1', 'name' => 'Total-Download', 'parent' => 'global', 'packet-mark' => '', 'max-limit' => '100M', 'limit-at' => '50M', 'priority' => '8', 'disabled' => 'false'],
            ['.id' => '*2', 'name' => 'Total-Upload', 'parent' => 'global', 'packet-mark' => '', 'max-limit' => '50M', 'limit-at' => '25M', 'priority' => '8', 'disabled' => 'false'],
        ];

        self::$store['/queue/type'] = [
            ['.id' => '*1', 'name' => 'default', 'kind' => 'pfifo', 'pfifo-limit' => '50'],
            ['.id' => '*2', 'name' => 'pcq-download-default', 'kind' => 'pcq', 'pcq-rate' => '0', 'pcq-limit' => '50KiB', 'pcq-classifier' => 'dst-address'],
            ['.id' => '*3', 'name' => 'pcq-upload-default', 'kind' => 'pcq', 'pcq-rate' => '0', 'pcq-limit' => '50KiB', 'pcq-classifier' => 'src-address'],
        ];

        self::$store['/ip/dhcp-server'] = [
            ['.id' => '*1', 'name' => 'dhcp1', 'interface' => 'bridge1', 'lease-time' => '12h', 'address-pool' => 'dhcp-pool-1', 'disabled' => 'false', 'authoritative' => 'yes'],
        ];

        self::$store['/ip/dhcp-server/network'] = [
            ['.id' => '*1', 'address' => '192.168.88.0/24', 'gateway' => '192.168.88.1', 'dns-server' => '192.168.88.1,8.8.8.8', 'comment' => 'LAN Subnet'],
        ];

        self::$store['/ip/dhcp-server/lease'] = [
            ['.id' => '*1', 'address' => '192.168.88.10', 'mac-address' => '00:11:22:33:44:55', 'client-id' => '1:0:11:22:33:44:55', 'server' => 'dhcp1', 'active-address' => '192.168.88.10', 'active-mac-address' => '00:11:22:33:44:55', 'host-name' => 'Printer-Office', 'status' => 'bound', 'dynamic' => 'false', 'disabled' => 'false', 'comment' => 'Static Office Printer'],
            ['.id' => '*2', 'address' => '192.168.88.100', 'mac-address' => 'AA:BB:CC:DD:EE:01', 'client-id' => '1:aa:bb:cc:dd:ee:1', 'server' => 'dhcp1', 'active-address' => '192.168.88.100', 'active-mac-address' => 'AA:BB:CC:DD:EE:01', 'host-name' => 'MacBook-Pro', 'status' => 'bound', 'dynamic' => 'true', 'disabled' => 'false', 'comment' => ''],
            ['.id' => '*3', 'address' => '192.168.88.101', 'mac-address' => 'AA:BB:CC:DD:EE:02', 'client-id' => '1:aa:bb:cc:dd:ee:2', 'server' => 'dhcp1', 'active-address' => '192.168.88.101', 'active-mac-address' => 'AA:BB:CC:DD:EE:02', 'host-name' => 'Android-Phone', 'status' => 'bound', 'dynamic' => 'true', 'disabled' => 'false', 'comment' => ''],
        ];

        self::$store['/ip/pool'] = [
            ['.id' => '*1', 'name' => 'dhcp-pool-1', 'ranges' => '192.168.88.10-192.168.88.250', 'next-pool' => 'none'],
            ['.id' => '*2', 'name' => 'hs-pool-1', 'ranges' => '192.168.88.50-192.168.88.99', 'next-pool' => 'none'],
            ['.id' => '*3', 'name' => 'pool1', 'ranges' => '10.0.0.10-10.0.0.100', 'next-pool' => 'none'],
        ];

        self::$store['/ip/pool/used'] = [
            ['.id' => '*1', 'pool' => 'dhcp-pool-1', 'address' => '192.168.88.100', 'owner' => 'dhcp', 'info' => 'MacBook-Pro'],
            ['.id' => '*2', 'pool' => 'dhcp-pool-1', 'address' => '192.168.88.101', 'owner' => 'dhcp', 'info' => 'Android-Phone'],
            ['.id' => '*3', 'pool' => 'hs-pool-1', 'address' => '192.168.88.50', 'owner' => 'hotspot', 'info' => 'vc-1001'],
        ];

        self::$store['/ip/firewall/nat'] = [
            ['.id' => '*1', 'chain' => 'srcnat', 'action' => 'masquerade', 'out-interface' => 'ether1', 'disabled' => 'false', 'comment' => 'Default NAT Masquerade'],
            ['.id' => '*2', 'chain' => 'dstnat', 'action' => 'dst-nat', 'protocol' => 'tcp', 'dst-port' => '8080', 'to-addresses' => '192.168.88.20', 'to-ports' => '80', 'disabled' => 'false', 'comment' => 'Port Forward Web Server'],
        ];

        self::$store['/ip/firewall/mangle'] = [
            ['.id' => '*1', 'chain' => 'prerouting', 'action' => 'mark-routing', 'new-routing-mark' => 'to_WAN2', 'src-address' => '192.168.88.100', 'passthrough' => 'yes', 'disabled' => 'false', 'comment' => 'Route VIP via WAN2'],
        ];

        self::$store['/ip/firewall/address-list'] = [
            ['.id' => '*1', 'list' => 'IP_Bypass', 'address' => '192.168.88.10', 'disabled' => 'false', 'comment' => 'Office Printer'],
            ['.id' => '*2', 'list' => 'Blocked_Scanners', 'address' => '198.51.100.23', 'timeout' => '1d', 'disabled' => 'false', 'comment' => 'Port scan detected'],
        ];

        self::$store['/ip/firewall/connection'] = [
            ['.id' => '*1', 'protocol' => 'tcp', 'src-address' => '192.168.88.100:54321', 'dst-address' => '1.1.1.1:443', 'reply-src-address' => '1.1.1.1:443', 'reply-dst-address' => '103.10.10.2:54321', 'tcp-state' => 'established', 'timeout' => '23h59m'],
            ['.id' => '*2', 'protocol' => 'udp', 'src-address' => '192.168.88.101:42134', 'dst-address' => '8.8.8.8:53', 'reply-src-address' => '8.8.8.8:53', 'reply-dst-address' => '103.10.10.2:42134', 'tcp-state' => '', 'timeout' => '10s'],
        ];

        self::$store['/ip/dns'] = [
            ['.id' => '*1', 'servers' => '8.8.8.8,1.1.1.1', 'dynamic-servers' => '192.168.1.1', 'allow-remote-requests' => 'true', 'cache-size' => '2048KiB', 'cache-used' => '128KiB'],
        ];

        self::$store['/ip/dns/static'] = [
            ['.id' => '*1', 'name' => 'router.lan', 'address' => '192.168.88.1', 'ttl' => '1d', 'disabled' => 'false', 'comment' => 'Router Gateway'],
            ['.id' => '*2', 'name' => 'nas.lan', 'address' => '192.168.88.15', 'ttl' => '1d', 'disabled' => 'false', 'comment' => 'Office NAS'],
        ];

        self::$store['/ip/arp'] = [
            ['.id' => '*1', 'address' => '192.168.88.10', 'mac-address' => '00:11:22:33:44:55', 'interface' => 'bridge1', 'dynamic' => 'false', 'complete' => 'true', 'disabled' => 'false', 'comment' => 'Office Printer'],
            ['.id' => '*2', 'address' => '192.168.88.100', 'mac-address' => 'AA:BB:CC:DD:EE:01', 'interface' => 'bridge1', 'dynamic' => 'true', 'complete' => 'true', 'disabled' => 'false', 'comment' => ''],
            ['.id' => '*3', 'address' => '192.168.88.254', 'mac-address' => '00:00:5E:00:01:01', 'interface' => 'ether1', 'dynamic' => 'true', 'complete' => 'true', 'disabled' => 'false', 'comment' => 'ISP Gateway'],
        ];

        self::$store['/interface/bridge'] = [
            ['.id' => '*1', 'name' => 'bridge1', 'mtu' => '1500', 'actual-mtu' => '1500', 'mac-address' => 'DC:2C:6E:00:00:03', 'arp' => 'enabled', 'fast-forward' => 'true', 'vlan-filtering' => 'no', 'disabled' => 'false', 'running' => 'true', 'comment' => 'Main LAN Bridge'],
        ];

        self::$store['/interface/bridge/port'] = [
            ['.id' => '*1', 'interface' => 'ether2', 'bridge' => 'bridge1', 'priority' => '0x80', 'path-cost' => '10', 'horizon' => 'none', 'trusted' => 'false', 'disabled' => 'false'],
            ['.id' => '*2', 'interface' => 'ether3', 'bridge' => 'bridge1', 'priority' => '0x80', 'path-cost' => '10', 'horizon' => 'none', 'trusted' => 'false', 'disabled' => 'false'],
        ];

        self::$store['/ip/service'] = [
            ['.id' => '*1', 'name' => 'api', 'port' => '8728', 'address' => '', 'disabled' => 'false'],
            ['.id' => '*2', 'name' => 'api-ssl', 'port' => '8729', 'address' => '', 'disabled' => 'true'],
            ['.id' => '*3', 'name' => 'ftp', 'port' => '21', 'address' => '', 'disabled' => 'true'],
            ['.id' => '*4', 'name' => 'ssh', 'port' => '22', 'address' => '', 'disabled' => 'false'],
            ['.id' => '*5', 'name' => 'telnet', 'port' => '23', 'address' => '', 'disabled' => 'true'],
            ['.id' => '*6', 'name' => 'winbox', 'port' => '8291', 'address' => '', 'disabled' => 'false'],
            ['.id' => '*7', 'name' => 'www', 'port' => '80', 'address' => '', 'disabled' => 'false'],
            ['.id' => '*8', 'name' => 'www-ssl', 'port' => '443', 'address' => '', 'disabled' => 'true'],
        ];

        self::$store['/user'] = [
            ['.id' => '*1', 'name' => 'admin', 'group' => 'full', 'address' => '', 'last-logged-in' => 'may/20/2026 09:12:30', 'disabled' => 'false', 'comment' => 'Super Administrator'],
            ['.id' => '*2', 'name' => 'operator', 'group' => 'write', 'address' => '192.168.88.0/24', 'last-logged-in' => 'may/19/2026 14:02:11', 'disabled' => 'false', 'comment' => 'NOC Operator'],
            ['.id' => '*3', 'name' => 'monit', 'group' => 'read', 'address' => '', 'last-logged-in' => 'may/20/2026 08:30:00', 'disabled' => 'false', 'comment' => 'Zabbix / Prometheus reader'],
        ];

        self::$store['/user/group'] = [
            ['.id' => '*1', 'name' => 'read', 'policy' => 'local,telnet,ssh,reboot,read,test,winbox,password,web,sniff,api,romon', 'comment' => 'Read only access'],
            ['.id' => '*2', 'name' => 'write', 'policy' => 'local,telnet,ssh,reboot,read,write,test,winbox,password,web,sniff,api,romon', 'comment' => 'Read and write access'],
            ['.id' => '*3', 'name' => 'full', 'policy' => 'local,telnet,ssh,reboot,read,write,policy,test,winbox,password,web,sniff,sensitive,api,romon,rest-api', 'comment' => 'Full administrative access'],
        ];

        self::$store['/user/active'] = [
            ['.id' => '*1', 'name' => 'admin', 'when' => 'may/20 09:12:30', 'address' => '192.168.88.100', 'via' => 'winbox'],
        ];

        self::$store['/system/scheduler'] = [
            ['.id' => '*1', 'name' => 'auto-backup', 'start-date' => 'jan/01/1970', 'start-time' => '03:00:00', 'interval' => '1d', 'on-event' => '/system script run daily-backup', 'run-count' => '42', 'disabled' => 'false', 'comment' => 'Daily automated backup'],
        ];

        self::$store['/system/identity'] = [
            ['.id' => '*1', 'name' => 'MikWeb-Router'],
        ];

        self::$store['/ip/dhcp-client'] = [
            ['.id' => '*1', 'interface' => 'ether1', 'status' => 'bound', 'address' => '10.0.0.88/24', 'gateway' => '10.0.0.1', 'dhcp-server' => '10.0.0.1', 'primary-dns' => '10.0.0.1', 'expires-after' => '23h50m', 'use-peer-dns' => 'yes', 'use-peer-ntp' => 'yes', 'add-default-route' => 'yes', 'default-route-distance' => '1', 'disabled' => 'false', 'comment' => 'WAN Uplink'],
        ];

        self::$store['/ip/neighbor'] = [
            ['.id' => '*1', 'interface' => 'ether1', 'mac-address' => 'DC:2C:6E:AA:00:01', 'address' => '10.0.0.1', 'identity' => 'Gateway-Core', 'platform' => 'MikroTik', 'version' => '7.15.3', 'board' => 'CCR2004-16G-2S+', 'uptime' => '12w4d'],
            ['.id' => '*2', 'interface' => 'bridge1', 'mac-address' => '00:15:6D:88:99:AA', 'address' => '192.168.88.2', 'identity' => 'AccessPoint-Lobby', 'platform' => 'MikroTik', 'version' => '7.15.3', 'board' => 'cAP ax', 'uptime' => '3w1d'],
        ];

        self::$store['/file'] = [
            ['.id' => '*1', 'name' => 'backup-weekly.backup', 'type' => 'backup', 'size' => '245829', 'creation-time' => 'oct/10/2026 03:00:00'],
            ['.id' => '*2', 'name' => 'firewall-rules.rsc', 'type' => 'script', 'size' => '8492', 'creation-time' => 'oct/11/2026 12:45:10'],
            ['.id' => '*3', 'name' => 'hotspot', 'type' => 'directory', 'size' => '4096', 'creation-time' => 'may/20/2026 09:00:00'],
        ];

        self::$store['/tool/netwatch'] = [
            ['.id' => '*1', 'host' => '8.8.8.8', 'interval' => '1m', 'timeout' => '1000ms', 'status' => 'up', 'since' => 'oct/10 08:00:00', 'up-script' => '', 'down-script' => ':log error "Google DNS down"', 'disabled' => 'false', 'comment' => 'Internet Gateway Monitor'],
            ['.id' => '*2', 'host' => '1.1.1.1', 'interval' => '1m', 'timeout' => '1000ms', 'status' => 'up', 'since' => 'oct/10 08:00:00', 'up-script' => '', 'down-script' => '', 'disabled' => 'false', 'comment' => 'Cloudflare DNS'],
        ];

        self::$store['/interface/wireguard'] = [
            ['.id' => '*1', 'name' => 'wireguard1', 'listen-port' => '13231', 'public-key' => 'eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHg=', 'mtu' => '1420', 'running' => 'true', 'disabled' => 'false', 'comment' => 'WG Main Gateway'],
        ];

        self::$store['/interface/wireguard/peers'] = [
            ['.id' => '*1', 'interface' => 'wireguard1', 'public-key' => 'YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=', 'allowed-address' => '10.10.0.2/32', 'endpoint-address' => '203.0.113.5', 'endpoint-port' => '13231', 'latest-handshake' => '1m12s', 'rx' => '1849204', 'tx' => '928472', 'disabled' => 'false', 'comment' => 'Branch Office 1'],
            ['.id' => '*2', 'interface' => 'wireguard1', 'public-key' => 'YmJiamJiamJiamJiamJiamJiamJiamJiamJiamJiamJi=', 'allowed-address' => '10.10.0.3/32', 'endpoint-address' => '', 'endpoint-port' => '', 'latest-handshake' => '', 'rx' => '0', 'tx' => '0', 'disabled' => 'false', 'comment' => 'Remote Admin Laptop'],
        ];

        self::$store['/radius'] = [
            ['.id' => '*1', 'service' => 'ppp,hotspot', 'address' => '192.168.88.250', 'secret' => 'radiussecret123', 'authentication-port' => '1812', 'accounting-port' => '1813', 'timeout' => '300ms', 'disabled' => 'false', 'comment' => 'User Manager Server'],
        ];

        self::$store['/system/clock'] = [
            ['time' => '08:58:00', 'date' => 'oct/12/2026', 'time-zone-name' => 'Asia/Jakarta', 'gmt-offset' => '+07:00'],
        ];

        self::$store['/system/ntp/client'] = [
            ['enabled' => 'true', 'servers' => 'id.pool.ntp.org,pool.ntp.org', 'status' => 'synchronized'],
        ];
    }
}

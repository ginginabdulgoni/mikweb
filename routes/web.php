<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\SecurityController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\AutomationController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\BulkController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\NetworkMapController;
use App\Http\Controllers\NotificationSettingController;
use App\Http\Controllers\Router\Modules\ArpController;
use App\Http\Controllers\Router\Modules\BridgeController;
use App\Http\Controllers\Router\Modules\DhcpClientController;
use App\Http\Controllers\Router\Modules\DhcpController;
use App\Http\Controllers\Router\Modules\DnsController;
use App\Http\Controllers\Router\Modules\FileController;
use App\Http\Controllers\Router\Modules\FirewallController;
use App\Http\Controllers\Router\Modules\HotspotController;
use App\Http\Controllers\Router\Modules\InterfaceController;
use App\Http\Controllers\Router\Modules\IpAddressController;
use App\Http\Controllers\Router\Modules\IpPoolController;
use App\Http\Controllers\Router\Modules\IpServiceController;
use App\Http\Controllers\Router\Modules\LogController;
use App\Http\Controllers\Router\Modules\NeighborController;
use App\Http\Controllers\Router\Modules\PppController;
use App\Http\Controllers\Router\Modules\QueueController;
use App\Http\Controllers\Router\Modules\RadiusController;
use App\Http\Controllers\Router\Modules\RouteController;
use App\Http\Controllers\Router\Modules\SecurityAuditController;
use App\Http\Controllers\Router\Modules\SystemController;
use App\Http\Controllers\Router\Modules\SystemUserController;
use App\Http\Controllers\Router\Modules\TerminalController;
use App\Http\Controllers\Router\Modules\ToolsController;
use App\Http\Controllers\Router\Modules\WireguardController;
use App\Http\Controllers\Router\RouterConnectionController;
use App\Http\Controllers\Router\RouterController;
use App\Http\Controllers\SystemUpdateController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

// ── Guest ────────────────────────────────────────────────────────────
Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:20,1');

    // 2FA challenge (second login step — user not yet authenticated).
    Route::get('two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.challenge');
    Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->middleware('throttle:10,1');
});

// ── Authenticated ────────────────────────────────────────────────────
Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::resource('routers', RouterController::class);
    Route::post('routers/{router}/test', [RouterConnectionController::class, 'test'])->name('routers.test');
    Route::post('routers/{router}/sync', [RouterConnectionController::class, 'sync'])->name('routers.sync');

    // ── Per-router modules ───────────────────────────────────────────
    Route::prefix('routers/{router}')->name('routers.')->group(function () {
        // Interfaces
        Route::get('interfaces', [InterfaceController::class, 'index'])->name('interfaces.index');
        Route::get('interfaces/traffic', [InterfaceController::class, 'traffic'])->name('interfaces.traffic');
        Route::post('interfaces/enable', [InterfaceController::class, 'enable'])->name('interfaces.enable');
        Route::post('interfaces/disable', [InterfaceController::class, 'disable'])->name('interfaces.disable');
        Route::post('interfaces/reset-counters', [InterfaceController::class, 'resetCounters'])->name('interfaces.reset');
        Route::post('interfaces/batch', [InterfaceController::class, 'batch'])->name('interfaces.batch');

        // Bridge
        Route::get('bridge', [BridgeController::class, 'index'])->name('bridge.index');
        Route::post('bridge/bridges', [BridgeController::class, 'storeBridge'])->name('bridge.bridges.store');
        Route::post('bridge/bridges/toggle', [BridgeController::class, 'toggleBridge'])->name('bridge.bridges.toggle');
        Route::post('bridge/bridges/batch', [BridgeController::class, 'batchBridges'])->name('bridge.bridges.batch');
        Route::delete('bridge/bridges', [BridgeController::class, 'destroyBridge'])->name('bridge.bridges.destroy');
        Route::post('bridge/ports', [BridgeController::class, 'storePort'])->name('bridge.ports.store');
        Route::post('bridge/ports/toggle', [BridgeController::class, 'togglePort'])->name('bridge.ports.toggle');
        Route::post('bridge/ports/batch', [BridgeController::class, 'batchPorts'])->name('bridge.ports.batch');
        Route::delete('bridge/ports', [BridgeController::class, 'destroyPort'])->name('bridge.ports.destroy');

        // IP Addresses
        Route::get('ip/addresses', [IpAddressController::class, 'index'])->name('ip.addresses.index');
        Route::post('ip/addresses', [IpAddressController::class, 'store'])->name('ip.addresses.store');
        Route::put('ip/addresses', [IpAddressController::class, 'update'])->name('ip.addresses.update');
        Route::post('ip/addresses/toggle', [IpAddressController::class, 'toggle'])->name('ip.addresses.toggle');
        Route::post('ip/addresses/batch', [IpAddressController::class, 'batch'])->name('ip.addresses.batch');
        Route::delete('ip/addresses', [IpAddressController::class, 'destroy'])->name('ip.addresses.destroy');

        // Static routes
        Route::get('ip/routes', [RouteController::class, 'index'])->name('ip.routes.index');
        Route::post('ip/routes', [RouteController::class, 'store'])->name('ip.routes.store');
        Route::post('ip/routes/toggle', [RouteController::class, 'toggle'])->name('ip.routes.toggle');
        Route::post('ip/routes/batch', [RouteController::class, 'batch'])->name('ip.routes.batch');
        Route::delete('ip/routes', [RouteController::class, 'destroy'])->name('ip.routes.destroy');

        // IP ARP
        Route::get('ip/arp', [ArpController::class, 'index'])->name('ip.arp.index');
        Route::post('ip/arp', [ArpController::class, 'store'])->name('ip.arp.store');
        Route::post('ip/arp/make-static', [ArpController::class, 'makeStatic'])->name('ip.arp.make-static');
        Route::post('ip/arp/toggle', [ArpController::class, 'toggle'])->name('ip.arp.toggle');
        Route::post('ip/arp/batch', [ArpController::class, 'batch'])->name('ip.arp.batch');
        Route::delete('ip/arp', [ArpController::class, 'destroy'])->name('ip.arp.destroy');

        // IP DHCP Client
        Route::get('ip/dhcp-client', [DhcpClientController::class, 'index'])->name('ip.dhcp-client.index');
        Route::post('ip/dhcp-client', [DhcpClientController::class, 'store'])->name('ip.dhcp-client.store');
        Route::put('ip/dhcp-client', [DhcpClientController::class, 'update'])->name('ip.dhcp-client.update');
        Route::post('ip/dhcp-client/toggle', [DhcpClientController::class, 'toggle'])->name('ip.dhcp-client.toggle');
        Route::post('ip/dhcp-client/release', [DhcpClientController::class, 'release'])->name('ip.dhcp-client.release');
        Route::post('ip/dhcp-client/renew', [DhcpClientController::class, 'renew'])->name('ip.dhcp-client.renew');
        Route::post('ip/dhcp-client/batch', [DhcpClientController::class, 'batch'])->name('ip.dhcp-client.batch');
        Route::delete('ip/dhcp-client', [DhcpClientController::class, 'destroy'])->name('ip.dhcp-client.destroy');

        // IP DHCP Server & Leases & Pools
        Route::get('ip/dhcp', [DhcpController::class, 'index'])->name('ip.dhcp.index');
        Route::post('ip/dhcp/leases', [DhcpController::class, 'storeLease'])->name('ip.dhcp.leases.store');
        Route::put('ip/dhcp/leases', [DhcpController::class, 'updateLease'])->name('ip.dhcp.leases.update');
        Route::post('ip/dhcp/leases/make-static', [DhcpController::class, 'makeStaticLease'])->name('ip.dhcp.leases.make-static');
        Route::post('ip/dhcp/leases/toggle', [DhcpController::class, 'toggleLease'])->name('ip.dhcp.leases.toggle');
        Route::post('ip/dhcp/leases/batch', [DhcpController::class, 'batchLeases'])->name('ip.dhcp.leases.batch');
        Route::delete('ip/dhcp/leases', [DhcpController::class, 'destroyLease'])->name('ip.dhcp.leases.destroy');
        Route::post('ip/dhcp/servers', [DhcpController::class, 'storeServer'])->name('ip.dhcp.servers.store');
        Route::post('ip/dhcp/servers/toggle', [DhcpController::class, 'toggleServer'])->name('ip.dhcp.servers.toggle');
        Route::delete('ip/dhcp/servers', [DhcpController::class, 'destroyServer'])->name('ip.dhcp.servers.destroy');
        Route::post('ip/dhcp/networks', [DhcpController::class, 'storeNetwork'])->name('ip.dhcp.networks.store');
        Route::delete('ip/dhcp/networks', [DhcpController::class, 'destroyNetwork'])->name('ip.dhcp.networks.destroy');
        Route::post('ip/dhcp/pools', [DhcpController::class, 'storePool'])->name('ip.dhcp.pools.store');
        Route::delete('ip/dhcp/pools', [DhcpController::class, 'destroyPool'])->name('ip.dhcp.pools.destroy');

        // IP Pool
        Route::get('ip/pool', [IpPoolController::class, 'index'])->name('ip.pool.index');
        Route::post('ip/pool', [IpPoolController::class, 'store'])->name('ip.pool.store');
        Route::put('ip/pool', [IpPoolController::class, 'update'])->name('ip.pool.update');
        Route::post('ip/pool/batch', [IpPoolController::class, 'batch'])->name('ip.pool.batch');
        Route::delete('ip/pool', [IpPoolController::class, 'destroy'])->name('ip.pool.destroy');

        // IP Neighbors
        Route::get('ip/neighbors', [NeighborController::class, 'index'])->name('ip.neighbors.index');

        // IP DNS
        Route::get('ip/dns', [DnsController::class, 'index'])->name('ip.dns.index');
        Route::post('ip/dns/settings', [DnsController::class, 'updateSettings'])->name('ip.dns.settings.update');
        Route::post('ip/dns/static', [DnsController::class, 'storeStatic'])->name('ip.dns.static.store');
        Route::put('ip/dns/static', [DnsController::class, 'updateStatic'])->name('ip.dns.static.update');
        Route::post('ip/dns/static/toggle', [DnsController::class, 'toggleStatic'])->name('ip.dns.static.toggle');
        Route::post('ip/dns/static/batch', [DnsController::class, 'batchStatic'])->name('ip.dns.static.batch');
        Route::delete('ip/dns/static', [DnsController::class, 'destroyStatic'])->name('ip.dns.static.destroy');

        // IP Services
        Route::get('ip/services', [IpServiceController::class, 'index'])->name('ip.services.index');
        Route::put('ip/services', [IpServiceController::class, 'update'])->name('ip.services.update');
        Route::post('ip/services/toggle', [IpServiceController::class, 'toggle'])->name('ip.services.toggle');
        Route::post('ip/services/batch', [IpServiceController::class, 'batch'])->name('ip.services.batch');

        // PPP
        Route::get('ppp', [PppController::class, 'index'])->name('ppp.index');
        Route::post('ppp/secrets', [PppController::class, 'store'])->name('ppp.secrets.store');
        Route::put('ppp/secrets', [PppController::class, 'update'])->name('ppp.secrets.update');
        Route::put('ppp/secrets/password', [PppController::class, 'updatePassword'])->name('ppp.secrets.password');
        Route::post('ppp/secrets/toggle', [PppController::class, 'toggle'])->name('ppp.secrets.toggle');
        Route::post('ppp/secrets/isolate', [PppController::class, 'isolateSecret'])->name('ppp.secrets.isolate');
        Route::post('ppp/secrets/restore', [PppController::class, 'restoreSecret'])->name('ppp.secrets.restore');
        Route::post('ppp/secrets/batch', [PppController::class, 'batchSecrets'])->name('ppp.secrets.batch');
        Route::post('ppp/secrets/generate', [PppController::class, 'generateSecrets'])->name('ppp.secrets.generate');
        Route::delete('ppp/secrets', [PppController::class, 'destroy'])->name('ppp.secrets.destroy');
        Route::post('ppp/active/disconnect', [PppController::class, 'disconnect'])->name('ppp.active.disconnect');
        Route::post('ppp/active/disconnect-all', [PppController::class, 'disconnectAllActive'])->name('ppp.active.disconnect-all');
        Route::post('ppp/profiles', [PppController::class, 'storeProfile'])->name('ppp.profiles.store');
        Route::put('ppp/profiles', [PppController::class, 'updateProfile'])->name('ppp.profiles.update');
        Route::delete('ppp/profiles', [PppController::class, 'destroyProfile'])->name('ppp.profiles.destroy');
        Route::post('ppp/servers', [PppController::class, 'storeServer'])->name('ppp.servers.store');
        Route::put('ppp/servers', [PppController::class, 'updateServer'])->name('ppp.servers.update');
        Route::post('ppp/servers/toggle', [PppController::class, 'toggleServer'])->name('ppp.servers.toggle');
        Route::delete('ppp/servers', [PppController::class, 'destroyServer'])->name('ppp.servers.destroy');
        Route::put('ppp/aaa', [PppController::class, 'updateAaa'])->name('ppp.aaa.update');

        // Hotspot
        Route::get('hotspot', [HotspotController::class, 'index'])->name('hotspot.index');
        Route::get('hotspot/vouchers/print', [HotspotController::class, 'printVouchers'])->name('hotspot.vouchers.print');
        Route::post('hotspot/vouchers/generate', [HotspotController::class, 'generateVouchers'])->name('hotspot.vouchers.generate');
        Route::post('hotspot/users', [HotspotController::class, 'storeUser'])->name('hotspot.users.store');
        Route::put('hotspot/users', [HotspotController::class, 'updateUser'])->name('hotspot.users.update');
        Route::put('hotspot/users/password', [HotspotController::class, 'updateUserPassword'])->name('hotspot.users.password');
        Route::post('hotspot/users/toggle', [HotspotController::class, 'toggleUser'])->name('hotspot.users.toggle');
        Route::delete('hotspot/users', [HotspotController::class, 'destroyUser'])->name('hotspot.users.destroy');
        Route::post('hotspot/users/batch', [HotspotController::class, 'batchUsers'])->name('hotspot.users.batch');
        Route::post('hotspot/active/disconnect', [HotspotController::class, 'disconnectActive'])->name('hotspot.active.disconnect');
        Route::post('hotspot/active/disconnect-all', [HotspotController::class, 'disconnectAllActive'])->name('hotspot.active.disconnect-all');
        Route::post('hotspot/user-profiles', [HotspotController::class, 'storeUserProfile'])->name('hotspot.user-profiles.store');
        Route::put('hotspot/user-profiles', [HotspotController::class, 'updateUserProfile'])->name('hotspot.user-profiles.update');
        Route::delete('hotspot/user-profiles', [HotspotController::class, 'destroyUserProfile'])->name('hotspot.user-profiles.destroy');
        Route::post('hotspot/servers', [HotspotController::class, 'storeServer'])->name('hotspot.servers.store');
        Route::put('hotspot/servers', [HotspotController::class, 'updateServer'])->name('hotspot.servers.update');
        Route::post('hotspot/servers/toggle', [HotspotController::class, 'toggleServer'])->name('hotspot.servers.toggle');
        Route::delete('hotspot/servers', [HotspotController::class, 'destroyServer'])->name('hotspot.servers.destroy');
        Route::post('hotspot/server-profiles', [HotspotController::class, 'storeServerProfile'])->name('hotspot.server-profiles.store');
        Route::put('hotspot/server-profiles', [HotspotController::class, 'updateServerProfile'])->name('hotspot.server-profiles.update');
        Route::delete('hotspot/server-profiles', [HotspotController::class, 'destroyServerProfile'])->name('hotspot.server-profiles.destroy');
        Route::post('hotspot/ip-bindings', [HotspotController::class, 'storeIpBinding'])->name('hotspot.ip-bindings.store');
        Route::put('hotspot/ip-bindings', [HotspotController::class, 'updateIpBinding'])->name('hotspot.ip-bindings.update');
        Route::post('hotspot/ip-bindings/toggle', [HotspotController::class, 'toggleIpBinding'])->name('hotspot.ip-bindings.toggle');
        Route::delete('hotspot/ip-bindings', [HotspotController::class, 'destroyIpBinding'])->name('hotspot.ip-bindings.destroy');
        Route::post('hotspot/ip-bindings/quick-bypass', [HotspotController::class, 'quickBypass'])->name('hotspot.ip-bindings.quick-bypass');
        Route::delete('hotspot/hosts', [HotspotController::class, 'destroyHost'])->name('hotspot.hosts.destroy');

        // Queues
        Route::get('queues', [QueueController::class, 'index'])->name('queues.index');
        Route::post('queues/simple', [QueueController::class, 'store'])->name('queues.simple.store');
        Route::put('queues/simple', [QueueController::class, 'update'])->name('queues.simple.update');
        Route::put('queues/simple/quick-limit', [QueueController::class, 'quickLimit'])->name('queues.simple.quick-limit');
        Route::post('queues/simple/toggle', [QueueController::class, 'toggle'])->name('queues.simple.toggle');
        Route::delete('queues/simple', [QueueController::class, 'destroy'])->name('queues.simple.destroy');
        Route::post('queues/simple/batch', [QueueController::class, 'batchSimple'])->name('queues.simple.batch');
        Route::post('queues/simple/reset-counters', [QueueController::class, 'resetCounters'])->name('queues.simple.reset-counters');
        Route::post('queues/tree', [QueueController::class, 'storeTree'])->name('queues.tree.store');
        Route::put('queues/tree', [QueueController::class, 'updateTree'])->name('queues.tree.update');
        Route::post('queues/tree/toggle', [QueueController::class, 'toggleTree'])->name('queues.tree.toggle');
        Route::delete('queues/tree', [QueueController::class, 'destroyTree'])->name('queues.tree.destroy');
        Route::post('queues/type', [QueueController::class, 'storeType'])->name('queues.type.store');
        Route::delete('queues/type', [QueueController::class, 'destroyType'])->name('queues.type.destroy');

        // Firewall
        Route::get('firewall', [FirewallController::class, 'index'])->name('firewall.index');
        Route::post('firewall', [FirewallController::class, 'store'])->name('firewall.store');
        Route::post('firewall/toggle', [FirewallController::class, 'toggle'])->name('firewall.toggle');
        Route::delete('firewall', [FirewallController::class, 'destroy'])->name('firewall.destroy');
        Route::post('firewall/batch', [FirewallController::class, 'batchFilters'])->name('firewall.batch');
        Route::post('firewall/reset-counters', [FirewallController::class, 'resetFilterCounters'])->name('firewall.reset-counters');
        Route::post('firewall/nat', [FirewallController::class, 'storeNat'])->name('firewall.nat.store');
        Route::post('firewall/nat/toggle', [FirewallController::class, 'toggleNat'])->name('firewall.nat.toggle');
        Route::delete('firewall/nat', [FirewallController::class, 'destroyNat'])->name('firewall.nat.destroy');
        Route::post('firewall/nat/batch', [FirewallController::class, 'batchNat'])->name('firewall.nat.batch');
        Route::post('firewall/nat/reset-counters', [FirewallController::class, 'resetNatCounters'])->name('firewall.nat.reset-counters');
        Route::post('firewall/mangle', [FirewallController::class, 'storeMangle'])->name('firewall.mangle.store');
        Route::post('firewall/mangle/toggle', [FirewallController::class, 'toggleMangle'])->name('firewall.mangle.toggle');
        Route::delete('firewall/mangle', [FirewallController::class, 'destroyMangle'])->name('firewall.mangle.destroy');
        Route::post('firewall/address-list', [FirewallController::class, 'storeAddressList'])->name('firewall.address-list.store');
        Route::post('firewall/address-list/toggle', [FirewallController::class, 'toggleAddressList'])->name('firewall.address-list.toggle');
        Route::delete('firewall/address-list', [FirewallController::class, 'destroyAddressList'])->name('firewall.address-list.destroy');

        // System
        Route::get('system', [SystemController::class, 'index'])->name('system.index');
        Route::post('system/scripts', [SystemController::class, 'storeScript'])->name('system.scripts.store');
        Route::delete('system/scripts', [SystemController::class, 'destroyScript'])->name('system.scripts.destroy');
        Route::post('system/scripts/run', [SystemController::class, 'runScript'])->name('system.scripts.run');
        Route::post('system/schedulers', [SystemController::class, 'storeScheduler'])->name('system.schedulers.store');
        Route::post('system/schedulers/toggle', [SystemController::class, 'toggleScheduler'])->name('system.schedulers.toggle');
        Route::delete('system/schedulers', [SystemController::class, 'destroyScheduler'])->name('system.schedulers.destroy');
        Route::post('system/identity', [SystemController::class, 'updateIdentity'])->name('system.identity.update');
        Route::post('system/reboot', [SystemController::class, 'reboot'])->name('system.reboot');

        // System Users
        Route::get('system/users', [SystemUserController::class, 'index'])->name('system.users.index');
        Route::post('system/users', [SystemUserController::class, 'store'])->name('system.users.store');
        Route::put('system/users', [SystemUserController::class, 'update'])->name('system.users.update');
        Route::post('system/users/toggle', [SystemUserController::class, 'toggle'])->name('system.users.toggle');
        Route::post('system/users/batch', [SystemUserController::class, 'batch'])->name('system.users.batch');
        Route::delete('system/users', [SystemUserController::class, 'destroy'])->name('system.users.destroy');

        // Files
        Route::get('files', [FileController::class, 'index'])->name('files.index');
        Route::post('files/backup', [FileController::class, 'createBackup'])->name('files.backup');
        Route::post('files/batch', [FileController::class, 'batch'])->name('files.batch');
        Route::delete('files', [FileController::class, 'destroy'])->name('files.destroy');

        // Log
        Route::get('log', [LogController::class, 'index'])->name('log.index');

        // Tools & Netwatch
        Route::get('tools', [ToolsController::class, 'index'])->name('tools.index');
        Route::post('tools/ping', [ToolsController::class, 'ping'])->name('tools.ping');
        Route::post('tools/traceroute', [ToolsController::class, 'traceroute'])->name('tools.traceroute');
        Route::post('tools/torch', [ToolsController::class, 'torch'])->name('tools.torch');
        Route::post('tools/netwatch', [ToolsController::class, 'storeNetwatch'])->name('tools.netwatch.store');
        Route::put('tools/netwatch', [ToolsController::class, 'updateNetwatch'])->name('tools.netwatch.update');
        Route::post('tools/netwatch/toggle', [ToolsController::class, 'toggleNetwatch'])->name('tools.netwatch.toggle');
        Route::delete('tools/netwatch', [ToolsController::class, 'destroyNetwatch'])->name('tools.netwatch.destroy');

        // WireGuard
        Route::get('wireguard', [WireguardController::class, 'index'])->name('wireguard.index');
        Route::post('wireguard/interfaces', [WireguardController::class, 'storeInterface'])->name('wireguard.interfaces.store');
        Route::put('wireguard/interfaces', [WireguardController::class, 'updateInterface'])->name('wireguard.interfaces.update');
        Route::post('wireguard/interfaces/toggle', [WireguardController::class, 'toggleInterface'])->name('wireguard.interfaces.toggle');
        Route::delete('wireguard/interfaces', [WireguardController::class, 'destroyInterface'])->name('wireguard.interfaces.destroy');
        Route::post('wireguard/peers', [WireguardController::class, 'storePeer'])->name('wireguard.peers.store');
        Route::put('wireguard/peers', [WireguardController::class, 'updatePeer'])->name('wireguard.peers.update');
        Route::post('wireguard/peers/toggle', [WireguardController::class, 'togglePeer'])->name('wireguard.peers.toggle');
        Route::post('wireguard/peers/batch', [WireguardController::class, 'batchPeers'])->name('wireguard.peers.batch');
        Route::delete('wireguard/peers', [WireguardController::class, 'destroyPeer'])->name('wireguard.peers.destroy');

        // RADIUS
        Route::get('radius', [RadiusController::class, 'index'])->name('radius.index');
        Route::post('radius', [RadiusController::class, 'store'])->name('radius.store');
        Route::put('radius', [RadiusController::class, 'update'])->name('radius.update');
        Route::post('radius/toggle', [RadiusController::class, 'toggle'])->name('radius.toggle');
        Route::post('radius/batch', [RadiusController::class, 'batch'])->name('radius.batch');
        Route::delete('radius', [RadiusController::class, 'destroy'])->name('radius.destroy');

        // New Terminal
        Route::get('terminal', [TerminalController::class, 'index'])->name('terminal.index');
        Route::post('terminal/execute', [TerminalController::class, 'execute'])->name('terminal.execute');

        // Security Auditor
        Route::get('security/audit', [SecurityAuditController::class, 'index'])->name('security.audit');
        Route::post('security/audit/fix', [SecurityAuditController::class, 'fix'])->name('security.audit.fix');
    });

    // ── Monitoring ───────────────────────────────────────────────────
    Route::get('monitoring', [MonitoringController::class, 'index'])->name('monitoring.index');
    Route::get('monitoring/fleet-stats', [MonitoringController::class, 'fleetStats'])->name('monitoring.fleet-stats');
    Route::post('monitoring/collect', [MonitoringController::class, 'collect'])->name('monitoring.collect');
    Route::post('monitoring/routers/{router}/collect', [MonitoringController::class, 'collectRouter'])->name('monitoring.routers.collect');

    // ── Backups ──────────────────────────────────────────────────────
    Route::get('routers/{router}/backups', [BackupController::class, 'index'])->name('routers.backups.index');
    Route::post('routers/{router}/backups/export', [BackupController::class, 'export'])->name('routers.backups.export');
    Route::get('backups/{backup}/download', [BackupController::class, 'download'])->name('backups.download');

    // ── Automation ───────────────────────────────────────────────────
    Route::get('automation', [AutomationController::class, 'index'])->name('automation.index');
    Route::post('automation', [AutomationController::class, 'store'])->name('automation.store');
    Route::post('automation/{rule}/toggle', [AutomationController::class, 'toggle'])->name('automation.toggle');
    Route::delete('automation/{rule}', [AutomationController::class, 'destroy'])->name('automation.destroy');
    Route::post('automation/evaluate', [AutomationController::class, 'evaluate'])->name('automation.evaluate');

    // ── Bulk operations ──────────────────────────────────────────────
    Route::get('bulk', [BulkController::class, 'index'])->name('bulk.index');
    Route::post('bulk/test', [BulkController::class, 'test'])->name('bulk.test');
    Route::post('bulk/backup', [BulkController::class, 'backup'])->name('bulk.backup');
    Route::post('bulk/reboot', [BulkController::class, 'reboot'])->name('bulk.reboot');
    Route::post('bulk/sync', [BulkController::class, 'sync'])->name('bulk.sync');
    Route::post('bulk/command', [BulkController::class, 'command'])->name('bulk.command');

    // ── Global search ────────────────────────────────────────────────
    Route::get('search', [GlobalSearchController::class, 'index'])->name('search.index');

    // ── Account security (2FA) ───────────────────────────────────────
    Route::get('settings/security', [SecurityController::class, 'show'])->name('settings.security');
    Route::post('settings/security/2fa', [SecurityController::class, 'enable'])->name('settings.2fa.enable');
    Route::post('settings/security/2fa/confirm', [SecurityController::class, 'confirm'])->name('settings.2fa.confirm');
    Route::delete('settings/security/2fa', [SecurityController::class, 'disable'])->name('settings.2fa.disable');

    // ── Customers & Billing CRM ──────────────────────────────────────
    Route::resource('customers', CustomerController::class);
    Route::post('customers/{customer}/mark-paid', [CustomerController::class, 'markPaid'])->name('customers.mark-paid');
    Route::post('customers/{customer}/toggle-isolir', [CustomerController::class, 'toggleIsolir'])->name('customers.toggle-isolir');

    // ── Network Topology Map ──────────────────────────────────────────
    Route::get('network-map', [NetworkMapController::class, 'index'])->name('network.map');

    // ── Notification Settings (Telegram & WhatsApp) ───────────────────
    Route::get('settings/notifications', [NotificationSettingController::class, 'edit'])->name('settings.notifications.edit');
    Route::post('settings/notifications', [NotificationSettingController::class, 'update'])->name('settings.notifications.update');
    Route::post('settings/notifications/test', [NotificationSettingController::class, 'test'])->name('settings.notifications.test');

    // ── System Update ─────────────────────────────────────────────────
    Route::get('system/update', [SystemUpdateController::class, 'index'])->name('system.update.index');
    Route::post('system/update/check', [SystemUpdateController::class, 'check'])->name('system.update.check');
    Route::post('system/update/apply', [SystemUpdateController::class, 'apply'])->name('system.update.apply');
});

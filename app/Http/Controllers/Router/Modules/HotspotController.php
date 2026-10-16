<?php

declare(strict_types=1);

namespace App\Http\Controllers\Router\Modules;

use App\Domain\Audit\AuditLogger;
use App\Domain\Router\Data\RouterPresenter;
use App\Domain\Router\Models\Router;
use App\Http\Controllers\Controller;
use App\Services\RouterOS\ResourceClient;
use App\Support\Access\Permissions;
use App\Support\Access\RouterAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Hotspot management: Servers, Server Profiles, Users (vouchers),
 * User Profiles, Active sessions, IP Bindings (Bypass), and Hosts.
 * Essential for WISP, cafe, hotel, and public hotspot deployments.
 */
class HotspotController extends Controller
{
    use InteractsWithRouterModule;

    private const SERVER = '/ip/hotspot';

    private const SERVER_PROFILE = '/ip/hotspot/profile';

    private const USER = '/ip/hotspot/user';

    private const USER_PROFILE = '/ip/hotspot/user/profile';

    private const ACTIVE = '/ip/hotspot/active';

    private const IP_BINDING = '/ip/hotspot/ip-binding';

    private const HOST = '/ip/hotspot/host';

    public function printVouchers(Request $request, Router $router, ResourceClient $client): Response
    {
        $this->ensureModuleAccess($router, Permissions::HOTSPOT_VIEW);
        $read = $this->deviceRead(fn () => $client->list($router, self::USER, userId: $request->user()->id));

        return Inertia::render('Routers/Modules/HotspotPrint', [
            'router' => RouterPresenter::header($router),
            'users' => $read['data'],
            'dnsName' => (string) $request->input('dns_name', ''),
        ]);
    }

    public function generateVouchers(Request $request, Router $router, ResourceClient $client): JsonResponse
    {
        $this->ensureModuleAccess($router, Permissions::HOTSPOT_MANAGE);

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'between:1,200'],
            'server' => ['nullable', 'string', 'max:64'],
            'profile' => ['required', 'string', 'max:64'],
            'prefix' => ['nullable', 'string', 'max:16'],
            'length' => ['required', 'integer', 'between:3,16'],
            'charset' => ['required', 'string', 'in:numeric,lowercase,uppercase,alphanumeric'],
            'limit_uptime' => ['nullable', 'string', 'max:32'],
            'limit_bytes_total' => ['nullable', 'integer'],
            'price' => ['nullable', 'numeric'],
            'comment' => ['nullable', 'string', 'max:64'],
        ]);

        $quantity = (int) $data['quantity'];
        $prefix = (string) ($data['prefix'] ?? '');
        $len = (int) $data['length'];
        $charset = (string) $data['charset'];

        $pool = match ($charset) {
            'numeric' => '0123456789',
            'lowercase' => 'abcdefghijklmnopqrstuvwxyz',
            'uppercase' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            'alphanumeric' => '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ',
            default => '23456789abcdefghjkmnpqrstuvwxyz',
        };

        $generated = [];
        $uid = $request->user()->id;

        for ($i = 0; $i < $quantity; $i++) {
            $code = '';
            for ($c = 0; $c < $len; $c++) {
                $code .= $pool[random_int(0, strlen($pool) - 1)];
            }
            $username = $prefix.$code;
            $password = $username;

            $payload = [
                'name' => $username,
                'password' => $password,
                'profile' => $data['profile'],
            ];

            if (filled($data['server'] ?? null) && $data['server'] !== 'all') {
                $payload['server'] = $data['server'];
            }
            if (filled($data['limit_uptime'] ?? null)) {
                $payload['limit-uptime'] = $data['limit_uptime'];
            }
            if (filled($data['limit_bytes_total'] ?? null)) {
                $payload['limit-bytes-total'] = (string) $data['limit_bytes_total'];
            }

            $comment = trim(($data['comment'] ?? 'vc').($data['price'] ? ' | Rp '.number_format((float) $data['price'], 0, ',', '.') : ''));
            $payload['comment'] = $comment;

            try {
                $client->add($router, self::USER, $payload, $uid);
                $generated[] = [
                    'username' => $username,
                    'password' => $password,
                    'profile' => $data['profile'],
                    'limit_uptime' => $data['limit_uptime'] ?? null,
                    'price' => $data['price'] ?? null,
                    'comment' => $comment,
                ];
            } catch (\Throwable) {
                // If collision or error, ignore individual
            }
        }

        AuditLogger::record('hotspot.vouchers_generated', $router, new: ['quantity' => count($generated), 'profile' => $data['profile']], routerId: $router->id);

        return response()->json([
            'success' => true,
            'vouchers' => $generated,
            'count' => count($generated),
        ]);
    }

    public function index(Request $request, Router $router, ResourceClient $client): Response
    {
        $this->ensureModuleAccess($router, Permissions::HOTSPOT_VIEW);
        $router->load('capability');
        $uid = $request->user()->id;

        $servers = $this->deviceRead(fn () => $client->list($router, self::SERVER, userId: $uid));
        $serverProfiles = $this->deviceRead(fn () => $client->list($router, self::SERVER_PROFILE, userId: $uid));
        $users = $this->deviceRead(fn () => $client->list($router, self::USER, userId: $uid));
        $userProfiles = $this->deviceRead(fn () => $client->list($router, self::USER_PROFILE, userId: $uid));
        $active = $this->deviceRead(fn () => $client->list($router, self::ACTIVE, userId: $uid));
        $ipBindings = $this->deviceRead(fn () => $client->list($router, self::IP_BINDING, userId: $uid));
        $hosts = $this->deviceRead(fn () => $client->list($router, self::HOST, userId: $uid));
        $interfaces = $this->deviceRead(fn () => array_map(fn ($i) => $i['name'] ?? '', $client->list($router, '/interface', userId: $uid)));
        $pools = $this->deviceRead(fn () => $client->list($router, '/ip/pool', userId: $uid));

        // Map pool IDs (e.g. *1) to pool names
        $poolMap = [];
        foreach ($pools['data'] as $p) {
            if (isset($p['.id'], $p['name'])) {
                $poolMap[$p['.id']] = (string) $p['name'];
            }
        }

        $resolvePool = static function (?string $value) use ($poolMap): ?string {
            if ($value === null || $value === '') {
                return $value;
            }

            return $poolMap[$value] ?? $value;
        };

        $resolvedServers = array_map(function (array $s) use ($resolvePool): array {
            if (isset($s['address-pool'])) {
                $s['address-pool'] = $resolvePool($s['address-pool']);
            }

            return $s;
        }, $servers['data']);

        $resolvedUserProfiles = array_map(function (array $up) use ($resolvePool): array {
            if (isset($up['address-pool'])) {
                $up['address-pool'] = $resolvePool($up['address-pool']);
            }

            return $up;
        }, $userProfiles['data']);

        return Inertia::render('Routers/Modules/Hotspot', [
            'router' => RouterPresenter::header($router),
            'servers' => $resolvedServers,
            'serverProfiles' => $serverProfiles['data'],
            'users' => $users['data'],
            'userProfiles' => $resolvedUserProfiles,
            'active' => $active['data'],
            'ipBindings' => $ipBindings['data'],
            'hosts' => $hosts['data'],
            'interfaces' => array_values(array_filter($interfaces['data'])),
            'pools' => $pools['data'],
            'deviceError' => $users['error'] ?? $active['error'] ?? $servers['error'] ?? $pools['error'],
            'can' => ['manage' => app(RouterAccess::class)->allows($request->user(), Permissions::HOTSPOT_MANAGE, $router)],
        ]);
    }

    // ── Hotspot Users ────────────────────────────────────────────────

    public function storeUser(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::HOTSPOT_MANAGE);
        $data = $this->validateUser($request, true);

        return $this->deviceAction(function () use ($client, $router, $data, $request) {
            $client->add($router, self::USER, $this->userPayload($data), $request->user()->id);
            AuditLogger::record('hotspot-user.add', $router, new: ['name' => $data['name']], routerId: $router->id);
        }, "Hotspot user \"{$data['name']}\" created.");
    }

    public function updateUser(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::HOTSPOT_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $data = $this->validateUser($request, false);

        return $this->deviceAction(function () use ($client, $router, $id, $data, $request) {
            $client->set($router, self::USER, $id, $this->userPayload($data), $request->user()->id);
            AuditLogger::record('hotspot-user.set', $router, new: ['id' => $id, 'name' => $data['name']], routerId: $router->id);
        }, 'Hotspot user updated.');
    }

    public function toggleUser(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::HOTSPOT_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $disable = $request->boolean('disable');

        return $this->deviceAction(function () use ($client, $router, $id, $disable, $request) {
            $disable
                ? $client->disable($router, self::USER, $id, $request->user()->id)
                : $client->enable($router, self::USER, $id, $request->user()->id);
        }, $disable ? 'User disabled.' : 'User enabled.');
    }

    public function destroyUser(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::HOTSPOT_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, self::USER, $id, $request->user()->id);
            AuditLogger::record('hotspot-user.remove', $router, old: ['id' => $id], routerId: $router->id);
        }, 'Hotspot user removed.');
    }

    public function updateUserPassword(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::HOTSPOT_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $data = $request->validate([
            'password' => ['required', 'string', 'max:128'],
        ]);

        return $this->deviceAction(function () use ($client, $router, $id, $data, $request) {
            $client->set($router, self::USER, $id, ['password' => $data['password']], $request->user()->id);
            AuditLogger::record('hotspot-user.password', $router, new: ['id' => $id], routerId: $router->id);
        }, 'Password hotspot user berhasil diubah.');
    }

    public function batchUsers(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::HOTSPOT_MANAGE);
        $data = $request->validate([
            'action' => ['required', 'in:enable,disable,change_profile,destroy'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'string'],
            'target_profile' => ['nullable', 'string', 'max:64'],
        ]);

        $ids = array_values(array_filter($data['ids']));
        $action = $data['action'];
        $uid = $request->user()->id;

        return $this->deviceAction(function () use ($client, $router, $ids, $action, $data, $uid) {
            if ($action === 'enable' || $action === 'disable') {
                $disable = $action === 'disable';
                foreach ($ids as $id) {
                    $disable
                        ? $client->disable($router, self::USER, $id, $uid)
                        : $client->enable($router, self::USER, $id, $uid);
                }
                AuditLogger::record("hotspot-user.batch-{$action}", $router, new: ['count' => count($ids)], routerId: $router->id);

                return;
            }

            if ($action === 'change_profile') {
                $targetProfile = (string) ($data['target_profile'] ?? '');
                if ($targetProfile === '') {
                    throw new \InvalidArgumentException('Target profile is required for change_profile.');
                }
                foreach ($ids as $id) {
                    $client->set($router, self::USER, $id, ['profile' => $targetProfile], $uid);
                }
                AuditLogger::record('hotspot-user.batch-change-profile', $router, new: ['count' => count($ids), 'profile' => $targetProfile], routerId: $router->id);

                return;
            }

            if ($action === 'destroy') {
                foreach ($ids as $id) {
                    $client->remove($router, self::USER, $id, $uid);
                }
                AuditLogger::record('hotspot-user.batch-destroy', $router, old: ['count' => count($ids)], routerId: $router->id);
            }
        }, 'Batch operasi berhasil diterapkan pada '.count($ids).' user.');
    }

    // ── Active Sessions ──────────────────────────────────────────────

    public function disconnectActive(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::HOTSPOT_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, self::ACTIVE, $id, $request->user()->id);
            AuditLogger::record('hotspot-active.disconnect', $router, old: ['id' => $id], routerId: $router->id);
        }, 'Hotspot session disconnected.');
    }

    public function disconnectAllActive(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::HOTSPOT_MANAGE);
        $uid = $request->user()->id;

        return $this->deviceAction(function () use ($client, $router, $uid) {
            $sessions = $client->list($router, self::ACTIVE, userId: $uid);
            $count = 0;
            foreach ($sessions as $sess) {
                if (isset($sess['.id'])) {
                    $client->remove($router, self::ACTIVE, $sess['.id'], $uid);
                    $count++;
                }
            }
            AuditLogger::record('hotspot-active.disconnect-all', $router, old: ['count' => $count], routerId: $router->id);
        }, 'Semua sesi hotspot aktif berhasil di-disconnect.');
    }

    // ── User Profiles ────────────────────────────────────────────────

    public function storeUserProfile(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::HOTSPOT_MANAGE);
        $data = $this->validateUserProfile($request);

        return $this->deviceAction(function () use ($client, $router, $data, $request) {
            $client->add($router, self::USER_PROFILE, $this->userProfilePayload($data), $request->user()->id);
            AuditLogger::record('hotspot-user-profile.add', $router, new: ['name' => $data['name']], routerId: $router->id);
        }, "Hotspot user profile \"{$data['name']}\" created.");
    }

    public function updateUserProfile(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::HOTSPOT_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $data = $this->validateUserProfile($request);

        return $this->deviceAction(function () use ($client, $router, $id, $data, $request) {
            $client->set($router, self::USER_PROFILE, $id, $this->userProfilePayload($data), $request->user()->id);
            AuditLogger::record('hotspot-user-profile.set', $router, new: ['id' => $id, 'name' => $data['name']], routerId: $router->id);
        }, "Hotspot user profile \"{$data['name']}\" updated.");
    }

    public function destroyUserProfile(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::HOTSPOT_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, self::USER_PROFILE, $id, $request->user()->id);
            AuditLogger::record('hotspot-user-profile.remove', $router, old: ['id' => $id], routerId: $router->id);
        }, 'Hotspot user profile removed.');
    }

    // ── Hotspot Servers ──────────────────────────────────────────────

    public function storeServer(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::HOTSPOT_MANAGE);
        $data = $this->validateServer($request);

        return $this->deviceAction(function () use ($client, $router, $data, $request) {
            $client->add($router, self::SERVER, $this->serverPayload($data), $request->user()->id);
            AuditLogger::record('hotspot-server.add', $router, new: ['name' => $data['name']], routerId: $router->id);
        }, "Hotspot server \"{$data['name']}\" created.");
    }

    public function updateServer(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::HOTSPOT_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $data = $this->validateServer($request);

        return $this->deviceAction(function () use ($client, $router, $id, $data, $request) {
            $client->set($router, self::SERVER, $id, $this->serverPayload($data), $request->user()->id);
            AuditLogger::record('hotspot-server.set', $router, new: ['id' => $id, 'name' => $data['name']], routerId: $router->id);
        }, "Hotspot server \"{$data['name']}\" updated.");
    }

    public function toggleServer(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::HOTSPOT_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $disable = $request->boolean('disable');

        return $this->deviceAction(function () use ($client, $router, $id, $disable, $request) {
            $disable
                ? $client->disable($router, self::SERVER, $id, $request->user()->id)
                : $client->enable($router, self::SERVER, $id, $request->user()->id);
        }, $disable ? 'Hotspot server disabled.' : 'Hotspot server enabled.');
    }

    public function destroyServer(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::HOTSPOT_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, self::SERVER, $id, $request->user()->id);
            AuditLogger::record('hotspot-server.remove', $router, old: ['id' => $id], routerId: $router->id);
        }, 'Hotspot server removed.');
    }

    // ── IP Bindings (Bypass / Block) ─────────────────────────────────

    public function storeIpBinding(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::HOTSPOT_MANAGE);
        $data = $this->validateIpBinding($request);

        return $this->deviceAction(function () use ($client, $router, $data, $request) {
            $client->add($router, self::IP_BINDING, $this->ipBindingPayload($data), $request->user()->id);
            AuditLogger::record('hotspot-ip-binding.add', $router, new: ['mac' => $data['mac_address'] ?? ''], routerId: $router->id);
        }, 'IP binding created.');
    }

    public function updateIpBinding(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::HOTSPOT_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $data = $this->validateIpBinding($request);

        return $this->deviceAction(function () use ($client, $router, $id, $data, $request) {
            $client->set($router, self::IP_BINDING, $id, $this->ipBindingPayload($data), $request->user()->id);
            AuditLogger::record('hotspot-ip-binding.set', $router, new: ['id' => $id], routerId: $router->id);
        }, 'IP binding updated.');
    }

    public function toggleIpBinding(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::HOTSPOT_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $disable = $request->boolean('disable');

        return $this->deviceAction(function () use ($client, $router, $id, $disable, $request) {
            $disable
                ? $client->disable($router, self::IP_BINDING, $id, $request->user()->id)
                : $client->enable($router, self::IP_BINDING, $id, $request->user()->id);
        }, $disable ? 'Binding disabled.' : 'Binding enabled.');
    }

    public function destroyIpBinding(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::HOTSPOT_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, self::IP_BINDING, $id, $request->user()->id);
            AuditLogger::record('hotspot-ip-binding.remove', $router, old: ['id' => $id], routerId: $router->id);
        }, 'IP binding removed.');
    }

    public function quickBypass(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::HOTSPOT_MANAGE);
        $data = $request->validate([
            'mac_address' => ['required', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:64'],
            'server' => ['nullable', 'string', 'max:64'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = [
            'mac-address' => $data['mac_address'],
            'type' => 'bypassed',
            'server' => $data['server'] ?? 'all',
            'comment' => $data['comment'] ?? 'Bypassed from Device List',
        ];
        if (filled($data['address'] ?? null)) {
            $payload['address'] = $data['address'];
        }

        return $this->deviceAction(function () use ($client, $router, $payload, $request) {
            $client->add($router, self::IP_BINDING, $payload, $request->user()->id);
            AuditLogger::record('hotspot-ip-binding.quick-bypass', $router, new: ['mac' => $payload['mac-address']], routerId: $router->id);
        }, "Perangkat {$payload['mac-address']} berhasil di-bypass.");
    }

    public function destroyHost(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::HOTSPOT_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, self::HOST, $id, $request->user()->id);
            AuditLogger::record('hotspot-host.remove', $router, old: ['id' => $id], routerId: $router->id);
        }, 'Host entry removed.');
    }

    // ── Server Profiles ──────────────────────────────────────────────

    public function storeServerProfile(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::HOTSPOT_MANAGE);
        $data = $this->validateServerProfile($request);

        return $this->deviceAction(function () use ($client, $router, $data, $request) {
            $client->add($router, self::SERVER_PROFILE, $this->serverProfilePayload($data), $request->user()->id);
            AuditLogger::record('hotspot-server-profile.add', $router, new: ['name' => $data['name']], routerId: $router->id);
        }, "Server profile \"{$data['name']}\" created.");
    }

    public function updateServerProfile(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::HOTSPOT_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $data = $this->validateServerProfile($request);

        return $this->deviceAction(function () use ($client, $router, $id, $data, $request) {
            $client->set($router, self::SERVER_PROFILE, $id, $this->serverProfilePayload($data), $request->user()->id);
            AuditLogger::record('hotspot-server-profile.set', $router, new: ['id' => $id, 'name' => $data['name']], routerId: $router->id);
        }, "Server profile \"{$data['name']}\" updated.");
    }

    public function destroyServerProfile(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::HOTSPOT_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, self::SERVER_PROFILE, $id, $request->user()->id);
            AuditLogger::record('hotspot-server-profile.remove', $router, old: ['id' => $id], routerId: $router->id);
        }, 'Server profile removed.');
    }

    // ── Validation & Payloads ────────────────────────────────────────

    /** @return array<string, mixed> */
    private function validateServerProfile(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'hotspot_address' => ['nullable', 'string', 'max:64'],
            'dns_name' => ['nullable', 'string', 'max:64'],
            'html_directory' => ['nullable', 'string', 'max:64'],
            'login_by' => ['nullable', 'string', 'max:128'],
        ]);
    }

    /** @return array<string, scalar> */
    private function serverProfilePayload(array $data): array
    {
        $payload = ['name' => $data['name']];
        foreach ([
            'hotspot_address' => 'hotspot-address',
            'dns_name' => 'dns-name',
            'html_directory' => 'html-directory',
            'login_by' => 'login-by',
        ] as $input => $rosKey) {
            if (filled($data[$input] ?? null)) {
                $payload[$rosKey] = $data[$input];
            }
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function validateUser(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'password' => ['nullable', 'string', 'max:128'],
            'profile' => ['nullable', 'string', 'max:64'],
            'server' => ['nullable', 'string', 'max:64'],
            'mac_address' => ['nullable', 'string', 'max:32'],
            'limit_uptime' => ['nullable', 'string', 'max:32'],
            'limit_bytes_total' => ['nullable', 'string', 'max:32'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /** @return array<string, scalar> */
    private function userPayload(array $data): array
    {
        $payload = ['name' => $data['name']];
        foreach ([
            'password' => 'password',
            'profile' => 'profile',
            'server' => 'server',
            'mac_address' => 'mac-address',
            'limit_uptime' => 'limit-uptime',
            'limit_bytes_total' => 'limit-bytes-total',
            'comment' => 'comment',
        ] as $input => $rosKey) {
            if (filled($data[$input] ?? null)) {
                $payload[$rosKey] = $data[$input];
            }
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function validateUserProfile(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'rate_limit' => ['nullable', 'string', 'max:64'],
            'shared_users' => ['nullable', 'string', 'max:10'],
            'session_timeout' => ['nullable', 'string', 'max:32'],
            'idle_timeout' => ['nullable', 'string', 'max:32'],
            'keepalive_timeout' => ['nullable', 'string', 'max:32'],
            'status_autorefresh' => ['nullable', 'string', 'max:32'],
            'address_pool' => ['nullable', 'string', 'max:64'],
            'parent_queue' => ['nullable', 'string', 'max:64'],
            'mac_cookie_timeout' => ['nullable', 'string', 'max:32'],
        ]);
    }

    /** @return array<string, scalar> */
    private function userProfilePayload(array $data): array
    {
        $payload = ['name' => $data['name']];
        foreach ([
            'rate_limit' => 'rate-limit',
            'shared_users' => 'shared-users',
            'session_timeout' => 'session-timeout',
            'idle_timeout' => 'idle-timeout',
            'keepalive_timeout' => 'keepalive-timeout',
            'status_autorefresh' => 'status-autorefresh',
            'address_pool' => 'address-pool',
            'parent_queue' => 'parent-queue',
            'mac_cookie_timeout' => 'mac-cookie-timeout',
        ] as $input => $rosKey) {
            if (filled($data[$input] ?? null)) {
                $payload[$rosKey] = $data[$input];
            }
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function validateServer(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'interface' => ['required', 'string', 'max:64'],
            'profile' => ['nullable', 'string', 'max:64'],
            'address_pool' => ['nullable', 'string', 'max:64'],
            'idle_timeout' => ['nullable', 'string', 'max:32'],
        ]);
    }

    /** @return array<string, scalar> */
    private function serverPayload(array $data): array
    {
        $payload = [
            'name' => $data['name'],
            'interface' => $data['interface'],
        ];
        foreach ([
            'profile' => 'profile',
            'address_pool' => 'address-pool',
            'idle_timeout' => 'idle-timeout',
        ] as $input => $rosKey) {
            if (filled($data[$input] ?? null)) {
                $payload[$rosKey] = $data[$input];
            }
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function validateIpBinding(Request $request): array
    {
        return $request->validate([
            'mac_address' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:64'],
            'to_address' => ['nullable', 'string', 'max:64'],
            'server' => ['nullable', 'string', 'max:64'],
            'type' => ['required', 'in:bypassed,blocked,regular'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /** @return array<string, scalar> */
    private function ipBindingPayload(array $data): array
    {
        $payload = ['type' => $data['type']];
        foreach ([
            'mac_address' => 'mac-address',
            'address' => 'address',
            'to_address' => 'to-address',
            'server' => 'server',
            'comment' => 'comment',
        ] as $input => $rosKey) {
            if (filled($data[$input] ?? null)) {
                $payload[$rosKey] = $data[$input];
            }
        }

        return $payload;
    }
}

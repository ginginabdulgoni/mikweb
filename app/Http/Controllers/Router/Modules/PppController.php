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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PPP management: Secrets (PPPoE/PPTP/… user accounts) and Active sessions.
 * Highly relevant for ISP deployments.
 */
class PppController extends Controller
{
    use InteractsWithRouterModule;

    private const SECRET = '/ppp/secret';

    private const ACTIVE = '/ppp/active';

    private const PROFILE = '/ppp/profile';

    private const SERVER = '/interface/pppoe-server/server';

    private const AAA = '/ppp/aaa';

    public function index(Request $request, Router $router, ResourceClient $client): Response
    {
        $this->ensureModuleAccess($router, Permissions::PPP_VIEW);
        $router->load('capability');
        $uid = $request->user()->id;

        $secrets = $this->deviceRead(fn () => $client->list($router, self::SECRET, userId: $uid));
        $active = $this->deviceRead(fn () => $client->list($router, self::ACTIVE, userId: $uid));
        $profiles = $this->deviceRead(fn () => $client->list($router, self::PROFILE, userId: $uid));
        $servers = $this->deviceRead(fn () => $client->list($router, self::SERVER, userId: $uid));
        $interfaces = $this->deviceRead(fn () => array_map(fn ($i) => $i['name'] ?? '', $client->list($router, '/interface', userId: $uid)));
        $pools = $this->deviceRead(fn () => $client->list($router, '/ip/pool', userId: $uid));
        $aaa = $this->deviceRead(fn () => $client->list($router, self::AAA, userId: $uid)[0] ?? [
            'use-radius' => 'no',
            'accounting' => 'yes',
            'interim-update' => '0s',
        ]);

        // Map pool IDs (e.g. *1) and pool names to readable pool names
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

        $resolvedProfiles = array_map(function (array $prof) use ($resolvePool): array {
            if (isset($prof['remote-address'])) {
                $prof['remote-address'] = $resolvePool($prof['remote-address']);
            }
            if (isset($prof['local-address'])) {
                $prof['local-address'] = $resolvePool($prof['local-address']);
            }

            return $prof;
        }, $profiles['data']);

        $resolvedSecrets = array_map(function (array $sec) use ($resolvePool): array {
            if (isset($sec['remote-address'])) {
                $sec['remote-address'] = $resolvePool($sec['remote-address']);
            }
            if (isset($sec['local-address'])) {
                $sec['local-address'] = $resolvePool($sec['local-address']);
            }

            return $sec;
        }, $secrets['data']);

        return Inertia::render('Routers/Modules/Ppp', [
            'router' => RouterPresenter::header($router),
            'secrets' => $resolvedSecrets,
            'active' => $active['data'],
            'profiles' => $resolvedProfiles,
            'servers' => $servers['data'],
            'interfaces' => array_values(array_filter($interfaces['data'])),
            'pools' => $pools['data'],
            'aaa' => $aaa['data'] ?? ['use-radius' => 'no', 'accounting' => 'yes', 'interim-update' => '0s'],
            'deviceError' => $secrets['error'] ?? $active['error'] ?? $profiles['error'] ?? $pools['error'] ?? $aaa['error'] ?? null,
            'can' => ['manage' => app(RouterAccess::class)->allows($request->user(), Permissions::PPP_MANAGE, $router)],
        ]);
    }

    public function store(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::PPP_MANAGE);
        $data = $this->validateSecret($request, true);

        return $this->deviceAction(function () use ($client, $router, $data, $request) {
            $client->add($router, self::SECRET, $this->payload($data), $request->user()->id);
            AuditLogger::record('ppp-secret.add', $router, new: ['name' => $data['name'], 'service' => $data['service']], routerId: $router->id);
        }, "PPP secret \"{$data['name']}\" created.");
    }

    public function update(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::PPP_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $data = $this->validateSecret($request, false);

        return $this->deviceAction(function () use ($client, $router, $id, $data, $request) {
            $client->set($router, self::SECRET, $id, $this->payload($data), $request->user()->id);
            AuditLogger::record('ppp-secret.set', $router, new: ['id' => $id, 'name' => $data['name']], routerId: $router->id);
        }, 'PPP secret updated.');
    }

    public function toggle(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::PPP_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $disable = $request->boolean('disable');

        return $this->deviceAction(function () use ($client, $router, $id, $disable, $request) {
            $disable
                ? $client->disable($router, self::SECRET, $id, $request->user()->id)
                : $client->enable($router, self::SECRET, $id, $request->user()->id);
        }, $disable ? 'Secret disabled.' : 'Secret enabled.');
    }

    public function isolateSecret(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::PPP_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $uid = $request->user()->id;

        return $this->deviceAction(function () use ($client, $router, $id, $uid) {
            $profiles = $client->list($router, self::PROFILE, userId: $uid);
            $hasIsolir = in_array('isolir', array_column($profiles, 'name'), true);
            if (! $hasIsolir) {
                $client->add($router, self::PROFILE, [
                    'name' => 'isolir',
                    'rate-limit' => '128k/128k',
                    'comment' => 'Profil isolasi pelanggan otomatis',
                ], $uid);
            }

            $secrets = $client->list($router, self::SECRET, userId: $uid);
            $secret = collect($secrets)->firstWhere('.id', $id);
            $currentProfile = $secret['profile'] ?? 'default';
            $comment = (string) ($secret['comment'] ?? '');

            if (! str_contains($comment, '[ORIG:')) {
                $comment = "[ORIG:{$currentProfile}] ".$comment;
            }

            $client->set($router, self::SECRET, $id, [
                'profile' => 'isolir',
                'comment' => trim($comment),
            ], $uid);

            if ($secret && isset($secret['name'])) {
                $active = $client->list($router, self::ACTIVE, userId: $uid);
                foreach ($active as $sess) {
                    if (($sess['name'] ?? '') === $secret['name'] && isset($sess['.id'])) {
                        $client->remove($router, self::ACTIVE, $sess['.id'], $uid);
                    }
                }
            }

            AuditLogger::record('ppp-secret.isolate', $router, new: ['id' => $id, 'user' => $secret['name'] ?? ''], routerId: $router->id);
        }, 'Pelanggan berhasil diisolir (profil dialihkan ke isolir & sesi di-reconnect).');
    }

    public function restoreSecret(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::PPP_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $targetProfile = (string) $request->input('profile', '');
        $uid = $request->user()->id;

        return $this->deviceAction(function () use ($client, $router, $id, $targetProfile, $uid) {
            $secrets = $client->list($router, self::SECRET, userId: $uid);
            $secret = collect($secrets)->firstWhere('.id', $id);
            $comment = (string) ($secret['comment'] ?? '');

            if ($targetProfile === '' && preg_match('/\[ORIG:([^\]]+)\]/', $comment, $matches)) {
                $targetProfile = $matches[1];
            }
            if ($targetProfile === '') {
                $targetProfile = 'default';
            }

            $cleanComment = trim((string) preg_replace('/\[ORIG:[^\]]+\]\s*/', '', $comment));

            $client->set($router, self::SECRET, $id, [
                'profile' => $targetProfile,
                'comment' => $cleanComment,
            ], $uid);

            if ($secret && isset($secret['name'])) {
                $active = $client->list($router, self::ACTIVE, userId: $uid);
                foreach ($active as $sess) {
                    if (($sess['name'] ?? '') === $secret['name'] && isset($sess['.id'])) {
                        $client->remove($router, self::ACTIVE, $sess['.id'], $uid);
                    }
                }
            }

            AuditLogger::record('ppp-secret.restore', $router, new: ['id' => $id, 'profile' => $targetProfile], routerId: $router->id);
        }, "Isolasi pelanggan dibuka (profil dikembalikan ke {$targetProfile}).");
    }

    public function destroy(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::PPP_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, self::SECRET, $id, $request->user()->id);
            AuditLogger::record('ppp-secret.remove', $router, old: ['id' => $id], routerId: $router->id);
        }, 'PPP secret removed.');
    }

    /** Disconnect an active session by removing it from /ppp/active. */
    public function disconnect(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::PPP_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, self::ACTIVE, $id, $request->user()->id);
            AuditLogger::record('ppp-active.disconnect', $router, old: ['id' => $id], routerId: $router->id);
        }, 'Session disconnected.');
    }

    public function storeProfile(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::PPP_MANAGE);
        $data = $this->validateProfile($request);

        return $this->deviceAction(function () use ($client, $router, $data, $request) {
            $client->add($router, self::PROFILE, $this->profilePayload($data), $request->user()->id);
            AuditLogger::record('ppp-profile.add', $router, new: ['name' => $data['name']], routerId: $router->id);
        }, "PPP profile \"{$data['name']}\" created.");
    }

    public function updateProfile(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::PPP_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $data = $this->validateProfile($request);

        return $this->deviceAction(function () use ($client, $router, $id, $data, $request) {
            $client->set($router, self::PROFILE, $id, $this->profilePayload($data), $request->user()->id);
            AuditLogger::record('ppp-profile.set', $router, new: ['id' => $id, 'name' => $data['name']], routerId: $router->id);
        }, "PPP profile \"{$data['name']}\" updated.");
    }

    public function destroyProfile(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::PPP_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, self::PROFILE, $id, $request->user()->id);
            AuditLogger::record('ppp-profile.remove', $router, old: ['id' => $id], routerId: $router->id);
        }, 'PPP profile removed.');
    }

    public function storeServer(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::PPP_MANAGE);
        $data = $this->validateServer($request);

        return $this->deviceAction(function () use ($client, $router, $data, $request) {
            $client->add($router, self::SERVER, $this->serverPayload($data), $request->user()->id);
            AuditLogger::record('pppoe-server.add', $router, new: ['service_name' => $data['service_name']], routerId: $router->id);
        }, "PPPoE server \"{$data['service_name']}\" created.");
    }

    public function updateServer(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::PPP_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $data = $this->validateServer($request);

        return $this->deviceAction(function () use ($client, $router, $id, $data, $request) {
            $client->set($router, self::SERVER, $id, $this->serverPayload($data), $request->user()->id);
            AuditLogger::record('pppoe-server.set', $router, new: ['id' => $id, 'service_name' => $data['service_name']], routerId: $router->id);
        }, "PPPoE server \"{$data['service_name']}\" updated.");
    }

    public function toggleServer(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::PPP_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $disable = $request->boolean('disable');

        return $this->deviceAction(function () use ($client, $router, $id, $disable, $request) {
            $disable
                ? $client->disable($router, self::SERVER, $id, $request->user()->id)
                : $client->enable($router, self::SERVER, $id, $request->user()->id);
        }, $disable ? 'PPPoE server disabled.' : 'PPPoE server enabled.');
    }

    public function destroyServer(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::PPP_MANAGE);
        $id = $this->requireId((string) $request->input('id'));

        return $this->deviceAction(function () use ($client, $router, $id, $request) {
            $client->remove($router, self::SERVER, $id, $request->user()->id);
            AuditLogger::record('pppoe-server.remove', $router, old: ['id' => $id], routerId: $router->id);
        }, 'PPPoE server removed.');
    }

    public function updatePassword(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::PPP_MANAGE);
        $id = $this->requireId((string) $request->input('id'));
        $data = $request->validate([
            'password' => ['required', 'string', 'max:128'],
        ]);

        return $this->deviceAction(function () use ($client, $router, $id, $data, $request) {
            $client->set($router, self::SECRET, $id, ['password' => $data['password']], $request->user()->id);
            AuditLogger::record('ppp-secret.password', $router, new: ['id' => $id], routerId: $router->id);
        }, 'Password PPP secret berhasil diubah.');
    }

    public function batchSecrets(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::PPP_MANAGE);
        $data = $request->validate([
            'action' => ['required', 'in:enable,disable,isolate,restore,change_profile,destroy'],
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
                        ? $client->disable($router, self::SECRET, $id, $uid)
                        : $client->enable($router, self::SECRET, $id, $uid);
                }
                AuditLogger::record("ppp-secret.batch-{$action}", $router, new: ['count' => count($ids)], routerId: $router->id);

                return;
            }

            if ($action === 'change_profile') {
                $targetProfile = (string) ($data['target_profile'] ?? '');
                if ($targetProfile === '') {
                    throw new \InvalidArgumentException('Target profile is required for change_profile action.');
                }
                foreach ($ids as $id) {
                    $client->set($router, self::SECRET, $id, ['profile' => $targetProfile], $uid);
                }
                AuditLogger::record('ppp-secret.batch-change-profile', $router, new: ['count' => count($ids), 'profile' => $targetProfile], routerId: $router->id);

                return;
            }

            if ($action === 'destroy') {
                foreach ($ids as $id) {
                    $client->remove($router, self::SECRET, $id, $uid);
                }
                AuditLogger::record('ppp-secret.batch-destroy', $router, old: ['count' => count($ids)], routerId: $router->id);

                return;
            }

            if ($action === 'isolate') {
                $profiles = $client->list($router, self::PROFILE, userId: $uid);
                $hasIsolir = in_array('isolir', array_column($profiles, 'name'), true);
                if (! $hasIsolir) {
                    $client->add($router, self::PROFILE, [
                        'name' => 'isolir',
                        'rate-limit' => '128k/128k',
                        'comment' => 'Profil isolasi pelanggan otomatis',
                    ], $uid);
                }

                $secrets = $client->list($router, self::SECRET, userId: $uid);
                $active = $client->list($router, self::ACTIVE, userId: $uid);
                $secretMap = collect($secrets)->keyBy('.id');

                foreach ($ids as $id) {
                    $sec = $secretMap->get($id);
                    if (! $sec) {
                        continue;
                    }
                    $currentProfile = $sec['profile'] ?? 'default';
                    $comment = (string) ($sec['comment'] ?? '');
                    if (! str_contains($comment, '[ORIG:')) {
                        $comment = "[ORIG:{$currentProfile}] ".$comment;
                    }

                    $client->set($router, self::SECRET, $id, [
                        'profile' => 'isolir',
                        'comment' => trim($comment),
                    ], $uid);

                    if (isset($sec['name'])) {
                        foreach ($active as $sess) {
                            if (($sess['name'] ?? '') === $sec['name'] && isset($sess['.id'])) {
                                $client->remove($router, self::ACTIVE, $sess['.id'], $uid);
                            }
                        }
                    }
                }

                AuditLogger::record('ppp-secret.batch-isolate', $router, new: ['count' => count($ids)], routerId: $router->id);

                return;
            }

            if ($action === 'restore') {
                $secrets = $client->list($router, self::SECRET, userId: $uid);
                $active = $client->list($router, self::ACTIVE, userId: $uid);
                $secretMap = collect($secrets)->keyBy('.id');

                foreach ($ids as $id) {
                    $sec = $secretMap->get($id);
                    if (! $sec) {
                        continue;
                    }
                    $comment = (string) ($sec['comment'] ?? '');
                    $targetProfile = 'default';
                    if (preg_match('/\[ORIG:([^\]]+)\]/', $comment, $matches)) {
                        $targetProfile = $matches[1];
                    }
                    $cleanComment = trim((string) preg_replace('/\[ORIG:[^\]]+\]\s*/', '', $comment));

                    $client->set($router, self::SECRET, $id, [
                        'profile' => $targetProfile,
                        'comment' => $cleanComment,
                    ], $uid);

                    if (isset($sec['name'])) {
                        foreach ($active as $sess) {
                            if (($sess['name'] ?? '') === $sec['name'] && isset($sess['.id'])) {
                                $client->remove($router, self::ACTIVE, $sess['.id'], $uid);
                            }
                        }
                    }
                }

                AuditLogger::record('ppp-secret.batch-restore', $router, new: ['count' => count($ids)], routerId: $router->id);
            }
        }, 'Batch operasi berhasil diterapkan pada '.count($ids).' user.');
    }

    public function generateSecrets(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::PPP_MANAGE);
        $data = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:100'],
            'prefix' => ['required', 'string', 'max:24'],
            'start_index' => ['nullable', 'integer', 'min:1'],
            'user_format' => ['required', 'in:sequential,random'],
            'password_mode' => ['required', 'in:same,numeric,alphanumeric,custom'],
            'custom_password' => ['nullable', 'string', 'max:64'],
            'service' => ['required', 'in:any,pppoe,pptp,l2tp,ovpn,sstp'],
            'profile' => ['nullable', 'string', 'max:64'],
            'local_address' => ['nullable', 'string', 'max:64'],
            'remote_address' => ['nullable', 'string', 'max:64'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $uid = $request->user()->id;
        $count = (int) $data['count'];
        $startIndex = (int) ($data['start_index'] ?? 1);
        $prefix = (string) $data['prefix'];
        $comment = (string) ($data['comment'] ?? 'Batch Generated');

        return $this->deviceAction(function () use ($client, $router, $data, $count, $startIndex, $prefix, $comment, $uid) {
            $created = 0;
            for ($i = 0; $i < $count; $i++) {
                if ($data['user_format'] === 'sequential') {
                    $num = str_pad((string) ($startIndex + $i), 3, '0', STR_PAD_LEFT);
                    $username = "{$prefix}{$num}";
                } else {
                    $rand = substr(str_shuffle('abcdefghjkmnpqrstuvwxyz23456789'), 0, 5);
                    $username = "{$prefix}-{$rand}";
                }

                $password = match ($data['password_mode']) {
                    'same' => $username,
                    'numeric' => (string) rand(100000, 999999),
                    'alphanumeric' => substr(str_shuffle('abcdefghjkmnpqrstuvwxyz23456789'), 0, 8),
                    'custom' => filled($data['custom_password'] ?? null) ? (string) $data['custom_password'] : $username,
                    default => $username,
                };

                $payload = [
                    'name' => $username,
                    'password' => $password,
                    'service' => $data['service'],
                    'comment' => $comment,
                ];

                if (filled($data['profile'] ?? null)) {
                    $payload['profile'] = $data['profile'];
                }
                if (filled($data['local_address'] ?? null)) {
                    $payload['local-address'] = $data['local_address'];
                }
                if (filled($data['remote_address'] ?? null)) {
                    $payload['remote-address'] = $data['remote_address'];
                }

                $client->add($router, self::SECRET, $payload, $uid);
                $created++;
            }

            AuditLogger::record('ppp-secret.batch-generate', $router, new: ['prefix' => $prefix, 'count' => $created], routerId: $router->id);
        }, "Berhasil membuat {$count} akun PPP secret.");
    }

    public function disconnectAllActive(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::PPP_MANAGE);
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
            AuditLogger::record('ppp-active.disconnect-all', $router, old: ['count' => $count], routerId: $router->id);
        }, 'Semua sesi aktif berhasil di-disconnect.');
    }

    public function updateAaa(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::PPP_MANAGE);
        $data = $request->validate([
            'use_radius' => ['required', 'in:yes,no,true,false'],
            'accounting' => ['required', 'in:yes,no,true,false'],
            'interim_update' => ['nullable', 'string', 'max:32'],
        ]);

        $payload = [
            'use-radius' => in_array($data['use_radius'], ['yes', 'true'], true) ? 'yes' : 'no',
            'accounting' => in_array($data['accounting'], ['yes', 'true'], true) ? 'yes' : 'no',
            'interim-update' => filled($data['interim_update'] ?? null) ? (string) $data['interim_update'] : '0s',
        ];

        return $this->deviceAction(function () use ($client, $router, $payload, $request) {
            $client->action($router, self::AAA.'/set', $payload, $request->user()->id);
            AuditLogger::record('ppp-aaa.set', $router, new: $payload, routerId: $router->id);
        }, 'Pengaturan PPP AAA / RADIUS berhasil diperbarui.');
    }

    /** @return array<string, mixed> */
    private function validateSecret(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'password' => [$creating ? 'required' : 'nullable', 'string', 'max:128'],
            'service' => ['required', 'in:any,pppoe,pptp,l2tp,ovpn,sstp'],
            'profile' => ['nullable', 'string', 'max:64'],
            'local_address' => ['nullable', 'string', 'max:64'],
            'remote_address' => ['nullable', 'string', 'max:64'],
            'caller_id' => ['nullable', 'string', 'max:64'],
            'routes' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /** @return array<string, scalar> */
    private function payload(array $data): array
    {
        $payload = ['name' => $data['name'], 'service' => $data['service']];
        foreach ([
            'password' => 'password',
            'profile' => 'profile',
            'local_address' => 'local-address',
            'remote_address' => 'remote-address',
            'caller_id' => 'caller-id',
            'routes' => 'routes',
            'comment' => 'comment',
        ] as $input => $rosKey) {
            if (filled($data[$input] ?? null)) {
                $payload[$rosKey] = $data[$input];
            }
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function validateProfile(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'local_address' => ['nullable', 'string', 'max:64'],
            'remote_address' => ['nullable', 'string', 'max:64'],
            'bridge' => ['nullable', 'string', 'max:64'],
            'rate_limit' => ['nullable', 'string', 'max:64'],
            'dns_server' => ['nullable', 'string', 'max:64'],
            'only_one' => ['nullable', 'in:default,yes,no'],
            'change_tcp_mss' => ['nullable', 'in:default,yes,no'],
            'use_encryption' => ['nullable', 'in:default,yes,no,required'],
            'use_mpls' => ['nullable', 'in:default,yes,no,required'],
            'use_compression' => ['nullable', 'in:default,yes,no'],
            'address_list' => ['nullable', 'string', 'max:64'],
            'parent_queue' => ['nullable', 'string', 'max:64'],
            'session_timeout' => ['nullable', 'string', 'max:32'],
            'idle_timeout' => ['nullable', 'string', 'max:32'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /** @return array<string, scalar> */
    private function profilePayload(array $data): array
    {
        $payload = ['name' => $data['name']];
        foreach ([
            'local_address' => 'local-address',
            'remote_address' => 'remote-address',
            'bridge' => 'bridge',
            'rate_limit' => 'rate-limit',
            'dns_server' => 'dns-server',
            'only_one' => 'only-one',
            'change_tcp_mss' => 'change-tcp-mss',
            'use_encryption' => 'use-encryption',
            'use_mpls' => 'use-mpls',
            'use_compression' => 'use-compression',
            'address_list' => 'address-list',
            'parent_queue' => 'parent-queue',
            'session_timeout' => 'session-timeout',
            'idle_timeout' => 'idle-timeout',
            'comment' => 'comment',
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
            'service_name' => ['required', 'string', 'max:64'],
            'interface' => ['required', 'string', 'max:64'],
            'default_profile' => ['nullable', 'string', 'max:64'],
            'max_mtu' => ['nullable', 'string', 'max:10'],
            'max_mru' => ['nullable', 'string', 'max:10'],
            'one_session_per_host' => ['nullable', 'in:true,false,yes,no'],
        ]);
    }

    /** @return array<string, scalar> */
    private function serverPayload(array $data): array
    {
        $payload = [
            'service-name' => $data['service_name'],
            'interface' => $data['interface'],
        ];
        foreach ([
            'default_profile' => 'default-profile',
            'max_mtu' => 'max-mtu',
            'max_mru' => 'max-mru',
            'one_session_per_host' => 'one-session-per-host',
        ] as $input => $rosKey) {
            if (filled($data[$input] ?? null)) {
                $payload[$rosKey] = $data[$input];
            }
        }

        return $payload;
    }
}

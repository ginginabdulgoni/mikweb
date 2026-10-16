<?php

declare(strict_types=1);

namespace App\Http\Controllers\Router\Modules;

use App\Domain\Audit\AuditLogger;
use App\Domain\Router\Data\RouterPresenter;
use App\Domain\Router\Models\Router;
use App\Http\Controllers\Controller;
use App\Services\RouterOS\ResourceClient;
use App\Support\Access\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SecurityAuditController extends Controller
{
    use InteractsWithRouterModule;

    public function index(Request $request, Router $router, ResourceClient $client): Response
    {
        $this->ensureModuleAccess($router, Permissions::SYSTEM_VIEW);

        $uid = $request->user()->id;
        $findings = [];
        $score = 100;

        try {
            // 1. Audit IP Services
            $services = $client->list($router, '/ip/service', userId: $uid);
            $serviceMap = collect($services)->keyBy('name');

            // Check Telnet (port 23)
            $telnet = $serviceMap->get('telnet');
            if ($telnet && ($telnet['disabled'] ?? 'false') === 'false') {
                $score -= 15;
                $findings[] = [
                    'id' => 'telnet_enabled',
                    'category' => 'IP Service',
                    'title' => 'Layanan Telnet (Port 23) Masih Aktif',
                    'severity' => 'critical',
                    'detail' => 'Telnet mengirimkan username dan password dalam bentuk teks polos (unencrypted plaintext). Penyerang di jaringan dapat menyadap kredensial login.',
                    'recommendation' => 'Nonaktifkan Telnet dan gunakan SSH untuk akses command line yang terenkripsi.',
                    'fixable' => true,
                    'fix_action' => 'disable_telnet',
                ];
            }

            // Check FTP (port 21)
            $ftp = $serviceMap->get('ftp');
            if ($ftp && ($ftp['disabled'] ?? 'false') === 'false') {
                $score -= 10;
                $findings[] = [
                    'id' => 'ftp_enabled',
                    'category' => 'IP Service',
                    'title' => 'Layanan FTP (Port 21) Masih Aktif',
                    'severity' => 'warning',
                    'detail' => 'FTP mentransmisikan data file dan kredensial tanpa enkripsi.',
                    'recommendation' => 'Nonaktifkan FTP jika tidak digunakan secara aktif, gunakan SFTP / Web GUI / Winbox aman.',
                    'fixable' => true,
                    'fix_action' => 'disable_ftp',
                ];
            }

            // Check API (port 8728 plaintext)
            $api = $serviceMap->get('api');
            if ($api && ($api['disabled'] ?? 'false') === 'false' && empty($api['address'])) {
                $score -= 10;
                $findings[] = [
                    'id' => 'api_unrestricted',
                    'category' => 'IP Service',
                    'title' => 'Port API (8728) Terbuka Tanpa Pembatasan IP',
                    'severity' => 'warning',
                    'detail' => 'API RouterOS terbuka untuk semua IP publik (0.0.0.0/0) tanpa batasan whitelist IP.',
                    'recommendation' => 'Batasi parameter "address" pada service API hanya untuk IP server MikWeb ini.',
                    'fixable' => false,
                ];
            }

            // Check Winbox (port 8291)
            $winbox = $serviceMap->get('winbox');
            if ($winbox && ($winbox['disabled'] ?? 'false') === 'false' && empty($winbox['address'])) {
                $score -= 10;
                $findings[] = [
                    'id' => 'winbox_unrestricted',
                    'category' => 'IP Service',
                    'title' => 'Port WinBox (8291) Terbuka untuk Seluruh Internet',
                    'severity' => 'warning',
                    'detail' => 'Port Winbox terbuka ke seluruh subnet tanpa whitelist IP. Rentan terhadap serangan brute-force.',
                    'recommendation' => 'Gunakan firewall filter untuk membatasi akses Winbox hanya dari IP manajemen / VPN.',
                    'fixable' => false,
                ];
            }

            // 2. Audit DNS Open Resolver
            $dns = $client->list($router, '/ip/dns', userId: $uid);
            $dnsSetting = $dns[0] ?? [];
            if (($dnsSetting['allow-remote-requests'] ?? 'false') === 'true') {
                // Check if firewall blocks WAN DNS (port 53 UDP)
                $firewallRules = $client->list($router, '/ip/firewall/filter', userId: $uid);
                $hasDnsBlock = collect($firewallRules)->contains(function ($r) {
                    return ($r['chain'] ?? '') === 'input'
                        && ($r['action'] ?? '') === 'drop'
                        && ($r['dst-port'] ?? '') === '53';
                });

                if (! $hasDnsBlock) {
                    $score -= 20;
                    $findings[] = [
                        'id' => 'dns_open_resolver',
                        'category' => 'DNS & DDoS',
                        'title' => 'DNS Open Resolver Berbahaya Aktif',
                        'severity' => 'critical',
                        'detail' => 'Parameter "Allow Remote Requests" aktif tanpa filter firewall pemblokir port 53 di WAN. Router ini dapat dieksploitasi untuk serangan DNS Amplification DDoS.',
                        'recommendation' => 'Nonaktifkan "Allow Remote Requests" atau tambahkan rule firewall input drop port 53 UDP dari interface WAN.',
                        'fixable' => true,
                        'fix_action' => 'disable_dns_remote',
                    ];
                }
            }

            // 3. Audit System Users (Default admin user)
            $users = $client->list($router, '/user', userId: $uid);
            $hasDefaultAdmin = collect($users)->contains(function ($u) {
                return ($u['name'] ?? '') === 'admin' && ($u['disabled'] ?? 'false') === 'false';
            });

            if ($hasDefaultAdmin) {
                $score -= 10;
                $findings[] = [
                    'id' => 'default_admin_user',
                    'category' => 'Account Security',
                    'title' => 'User Default "admin" Masih Aktif',
                    'severity' => 'warning',
                    'detail' => 'Nama pengguna default "admin" merupakan target utama kamus serangan brute-force botnet.',
                    'recommendation' => 'Buat user baru dengan nama khusus berkewenangan full, lalu nonaktifkan user "admin".',
                    'fixable' => false,
                ];
            }

            // 4. Audit Firewall Filter Input Chain
            $filters = $client->list($router, '/ip/firewall/filter', userId: $uid);
            $inputFilters = collect($filters)->filter(fn ($f) => ($f['chain'] ?? '') === 'input');
            if ($inputFilters->isEmpty()) {
                $score -= 20;
                $findings[] = [
                    'id' => 'no_input_firewall',
                    'category' => 'Firewall',
                    'title' => 'Tidak Ada Rule Firewall Input Filter',
                    'severity' => 'critical',
                    'detail' => 'Router tidak memiliki firewall filter pada chain input untuk memproteksi port internal router.',
                    'recommendation' => 'Konfigurasikan minimal rule firewall: accept established/related, drop invalid, dan drop unauthorized WAN input.',
                    'fixable' => false,
                ];
            }
        } catch (\Throwable $e) {
            // Router might be offline or partial read
        }

        $score = max(0, min(100, $score));

        $grade = match (true) {
            $score >= 90 => 'A',
            $score >= 75 => 'B',
            $score >= 60 => 'C',
            default => 'D',
        };

        return Inertia::render('Routers/Modules/SecurityAudit', [
            'router' => RouterPresenter::header($router),
            'score' => $score,
            'grade' => $grade,
            'findings' => $findings,
        ]);
    }

    public function fix(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::SYSTEM_MANAGE);

        $action = (string) $request->input('action');
        $uid = $request->user()->id;

        return $this->deviceAction(function () use ($action, $router, $client, $uid) {
            if ($action === 'disable_telnet') {
                $services = $client->list($router, '/ip/service', userId: $uid);
                $telnet = collect($services)->firstWhere('name', 'telnet');
                if ($telnet && isset($telnet['.id'])) {
                    $client->set($router, '/ip/service', $telnet['.id'], ['disabled' => 'true'], $uid);
                }
            } elseif ($action === 'disable_ftp') {
                $services = $client->list($router, '/ip/service', userId: $uid);
                $ftp = collect($services)->firstWhere('name', 'ftp');
                if ($ftp && isset($ftp['.id'])) {
                    $client->set($router, '/ip/service', $ftp['.id'], ['disabled' => 'true'], $uid);
                }
            } elseif ($action === 'disable_dns_remote') {
                $client->action($router, '/ip/dns/set', ['allow-remote-requests' => 'no'], $uid);
            }

            AuditLogger::record('security.audit_fix', $router, new: ['action' => $action], routerId: $router->id);
        }, 'Perbaikan keamanan berhasil diterapkan ke router!');
    }
}

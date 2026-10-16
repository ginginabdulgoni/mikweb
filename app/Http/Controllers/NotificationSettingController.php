<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Notification\Models\NotificationSetting;
use App\Services\Alerts\NotificationDispatcher;
use App\Support\Access\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationSettingController extends Controller
{
    public function edit(Request $request): Response
    {
        $this->ensureAuthorized($request);

        $org = $request->user()->organization;
        $setting = NotificationSetting::firstOrCreate(
            ['organization_id' => $org->id],
            [
                'notify_router_down' => true,
                'notify_router_recovery' => true,
                'notify_high_cpu' => true,
                'high_cpu_threshold' => 90,
                'notify_daily_digest' => true,
                'notify_backup_completion' => true,
                'notify_security_alert' => true,
            ]
        );

        return Inertia::render('Settings/Notifications', [
            'settings' => [
                'has_telegram_token' => ! empty($setting->telegram_bot_token),
                'telegram_chat_id' => $setting->telegram_chat_id ?? '',
                'whatsapp_api_url' => $setting->whatsapp_api_url ?? 'https://mywifi.weagate.com',
                'has_whatsapp_token' => ! empty($setting->whatsapp_api_token),
                'whatsapp_target_phone' => $setting->whatsapp_target_phone ?? '',
                'whatsapp_device_id' => $setting->whatsapp_device_id ?? '',
                'notify_router_down' => (bool) $setting->notify_router_down,
                'notify_router_recovery' => (bool) $setting->notify_router_recovery,
                'notify_high_cpu' => (bool) $setting->notify_high_cpu,
                'high_cpu_threshold' => (int) $setting->high_cpu_threshold,
                'notify_daily_digest' => (bool) $setting->notify_daily_digest,
                'notify_backup_completion' => (bool) $setting->notify_backup_completion,
                'notify_security_alert' => (bool) $setting->notify_security_alert,
                'template_router_down' => $setting->template_router_down ?? NotificationSetting::defaultTemplate('router_down'),
                'template_router_recovery' => $setting->template_router_recovery ?? NotificationSetting::defaultTemplate('router_recovery'),
                'template_high_cpu' => $setting->template_high_cpu ?? NotificationSetting::defaultTemplate('high_cpu'),
                'template_daily_digest' => $setting->template_daily_digest ?? NotificationSetting::defaultTemplate('daily_digest'),
            ],
            'default_templates' => [
                'router_down' => NotificationSetting::defaultTemplate('router_down'),
                'router_recovery' => NotificationSetting::defaultTemplate('router_recovery'),
                'high_cpu' => NotificationSetting::defaultTemplate('high_cpu'),
                'daily_digest' => NotificationSetting::defaultTemplate('daily_digest'),
            ],
            'available_variables' => [
                'router_down' => [
                    ['key' => '{router_name}', 'desc' => 'Nama router (e.g. Core-MikroTik)'],
                    ['key' => '{router_host}', 'desc' => 'IP atau host domain router'],
                    ['key' => '{time}', 'desc' => 'Waktu kejadian (tanggal & jam)'],
                    ['key' => '{date}', 'desc' => 'Tanggal kejadian'],
                    ['key' => '{error}', 'desc' => 'Pesan error / alasan unreachable'],
                    ['key' => '{org_name}', 'desc' => 'Nama organisasi'],
                ],
                'router_recovery' => [
                    ['key' => '{router_name}', 'desc' => 'Nama router'],
                    ['key' => '{router_host}', 'desc' => 'IP atau host domain router'],
                    ['key' => '{time}', 'desc' => 'Waktu pemulihan'],
                    ['key' => '{date}', 'desc' => 'Tanggal kejadian'],
                    ['key' => '{status}', 'desc' => 'Status normal'],
                    ['key' => '{org_name}', 'desc' => 'Nama organisasi'],
                ],
                'high_cpu' => [
                    ['key' => '{router_name}', 'desc' => 'Nama router'],
                    ['key' => '{router_host}', 'desc' => 'IP atau host domain router'],
                    ['key' => '{cpu_load}', 'desc' => 'Persentase CPU saat ini (%)'],
                    ['key' => '{threshold}', 'desc' => 'Batas ambang alert (%)'],
                    ['key' => '{mem_percent}', 'desc' => 'Penggunaan RAM (%)'],
                    ['key' => '{time}', 'desc' => 'Waktu kejadian'],
                    ['key' => '{org_name}', 'desc' => 'Nama organisasi'],
                ],
                'daily_digest' => [
                    ['key' => '{org_name}', 'desc' => 'Nama organisasi'],
                    ['key' => '{date}', 'desc' => 'Tanggal laporan'],
                    ['key' => '{total_routers}', 'desc' => 'Total jumlah router terdaftar'],
                    ['key' => '{online_routers}', 'desc' => 'Jumlah router aktif/online'],
                    ['key' => '{down_routers}', 'desc' => 'Jumlah router mati/down'],
                    ['key' => '{avg_cpu}', 'desc' => 'Rata-rata CPU 24 jam (%)'],
                    ['key' => '{avg_latency}', 'desc' => 'Rata-rata Latensi 24 jam (ms)'],
                ],
            ],
            'sample_data' => [
                'router_name' => 'Core-MikroTik-CCR',
                'router_host' => '192.168.88.1',
                'time' => now()->format('d M Y H:i:s T'),
                'date' => now()->format('d M Y'),
                'error' => 'Connection timed out (10.0.0.1:8728)',
                'status' => 'Normal (Reachable)',
                'cpu_load' => '94',
                'threshold' => (string) ($setting->high_cpu_threshold ?: 90),
                'mem_percent' => '78',
                'org_name' => $org->name,
                'total_routers' => '8',
                'online_routers' => '7',
                'down_routers' => '1',
                'avg_cpu' => '42',
                'avg_latency' => '18',
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->ensureAuthorized($request);

        $data = $request->validate([
            'telegram_bot_token' => ['nullable', 'string', 'max:255'],
            'telegram_chat_id' => ['nullable', 'string', 'max:128'],
            'whatsapp_api_url' => ['nullable', 'url', 'max:255'],
            'whatsapp_api_token' => ['nullable', 'string', 'max:255'],
            'whatsapp_target_phone' => ['nullable', 'string', 'max:64'],
            'whatsapp_device_id' => ['nullable', 'string', 'max:64'],
            'notify_router_down' => ['boolean'],
            'notify_router_recovery' => ['boolean'],
            'notify_high_cpu' => ['boolean'],
            'high_cpu_threshold' => ['integer', 'between:50,99'],
            'notify_daily_digest' => ['boolean'],
            'notify_backup_completion' => ['boolean'],
            'notify_security_alert' => ['boolean'],
            'template_router_down' => ['nullable', 'string', 'max:3000'],
            'template_router_recovery' => ['nullable', 'string', 'max:3000'],
            'template_high_cpu' => ['nullable', 'string', 'max:3000'],
            'template_daily_digest' => ['nullable', 'string', 'max:3000'],
        ]);

        $org = $request->user()->organization;
        $setting = NotificationSetting::firstOrNew(['organization_id' => $org->id]);

        if (! empty($data['telegram_bot_token'])) {
            $setting->telegram_bot_token = $data['telegram_bot_token'];
        }
        $setting->telegram_chat_id = $data['telegram_chat_id'] ?? null;

        $setting->whatsapp_api_url = $data['whatsapp_api_url'] ?? 'https://mywifi.weagate.com';
        if (! empty($data['whatsapp_api_token'])) {
            $setting->whatsapp_api_token = $data['whatsapp_api_token'];
        }
        $setting->whatsapp_target_phone = $data['whatsapp_target_phone'] ?? null;
        $setting->whatsapp_device_id = $data['whatsapp_device_id'] ?? null;

        $setting->notify_router_down = (bool) ($data['notify_router_down'] ?? true);
        $setting->notify_router_recovery = (bool) ($data['notify_router_recovery'] ?? true);
        $setting->notify_high_cpu = (bool) ($data['notify_high_cpu'] ?? true);
        $setting->high_cpu_threshold = (int) ($data['high_cpu_threshold'] ?? 90);
        $setting->notify_daily_digest = (bool) ($data['notify_daily_digest'] ?? true);
        $setting->notify_backup_completion = (bool) ($data['notify_backup_completion'] ?? true);
        $setting->notify_security_alert = (bool) ($data['notify_security_alert'] ?? true);

        $setting->template_router_down = $data['template_router_down'] ?? null;
        $setting->template_router_recovery = $data['template_router_recovery'] ?? null;
        $setting->template_high_cpu = $data['template_high_cpu'] ?? null;
        $setting->template_daily_digest = $data['template_daily_digest'] ?? null;

        $setting->save();

        return back()->with('success', 'Pengaturan notifikasi & template alert berhasil disimpan.');
    }

    public function test(Request $request, NotificationDispatcher $dispatcher): JsonResponse
    {
        $this->ensureAuthorized($request);

        $org = $request->user()->organization;
        $setting = NotificationSetting::firstOrNew(['organization_id' => $org->id]);

        // Support in-memory override from request so user can test before saving
        if ($request->filled('telegram_bot_token')) {
            $setting->telegram_bot_token = $request->input('telegram_bot_token');
        }
        if ($request->filled('telegram_chat_id')) {
            $setting->telegram_chat_id = $request->input('telegram_chat_id');
        }

        if ($request->filled('whatsapp_api_url')) {
            $setting->whatsapp_api_url = $request->input('whatsapp_api_url');
        }
        if ($request->filled('whatsapp_api_token')) {
            $setting->whatsapp_api_token = $request->input('whatsapp_api_token');
        }
        if ($request->filled('whatsapp_target_phone')) {
            $setting->whatsapp_target_phone = $request->input('whatsapp_target_phone');
        }
        if ($request->has('whatsapp_device_id')) {
            $setting->whatsapp_device_id = $request->input('whatsapp_device_id') ?: $setting->whatsapp_device_id;
        }

        $channel = $request->input('channel', 'all'); // 'telegram', 'whatsapp', 'all'
        $eventType = $request->input('alert_type');

        // Validation for channel configuration
        if ($channel === 'telegram' && ! $setting->hasTelegram()) {
            return response()->json([
                'success' => false,
                'channel' => 'telegram',
                'telegram' => false,
                'message' => 'Telegram Bot Token atau Chat ID belum diisi.',
                'errors' => ['Bot Token atau Chat ID Telegram belum dikonfigurasi.'],
            ]);
        }

        if ($channel === 'whatsapp' && ! $setting->hasWhatsApp()) {
            return response()->json([
                'success' => false,
                'channel' => 'whatsapp',
                'whatsapp' => false,
                'message' => 'WhatsApp Device Token atau Nomor Tujuan belum diisi.',
                'errors' => ['Device Token atau Nomor Tujuan WhatsApp belum dikonfigurasi.'],
            ]);
        }

        $sampleVars = [
            'router_name' => 'Core-MikroTik-CCR',
            'router_host' => '192.168.88.1',
            'time' => now()->format('d M Y H:i:s T'),
            'date' => now()->format('d M Y'),
            'error' => 'Connection timed out (10.0.0.1:8728)',
            'status' => 'Normal (Reachable)',
            'cpu_load' => '94',
            'threshold' => '90',
            'mem_percent' => '78',
            'org_name' => $org->name,
            'total_routers' => '8',
            'online_routers' => '7',
            'down_routers' => '1',
            'avg_cpu' => '42',
            'avg_latency' => '18',
        ];

        if ($eventType && in_array($eventType, ['router_down', 'router_recovery', 'high_cpu', 'daily_digest'], true)) {
            $templateField = 'template_'.$eventType;
            if ($request->filled($templateField)) {
                $setting->{$templateField} = $request->input($templateField);
            }
            $testMsg = '';
        } else {
            $timeStr = now()->format('d M Y H:i:s T');
            $gatewayName = match ($channel) {
                'whatsapp' => 'Weagate WhatsApp Gateway',
                'telegram' => 'Telegram Bot Channel',
                default => 'Telegram & WhatsApp (Weagate)',
            };
            $testMsg = "<b>🔔 Test Notifikasi MikWeb</b>\n\n"
                ."Koneksi bot notifikasi berhasil terhubung!\n"
                ."Channel: <b>{$gatewayName}</b>\n"
                ."Waktu: <code>{$timeStr}</code>\n"
                ."Organisasi: <b>{$org->name}</b>\n\n"
                .'<i>MikWeb MikroTik Fleet Management</i>';
        }

        $res = $dispatcher->send($org, $testMsg, $eventType, $sampleVars, $channel, $setting);

        $isSuccess = match ($channel) {
            'telegram' => $res['telegram'],
            'whatsapp' => $res['whatsapp'],
            default => $res['telegram'] || $res['whatsapp'],
        };

        $successMsg = match ($channel) {
            'telegram' => 'Pesan test berhasil terkirim ke Telegram!',
            'whatsapp' => 'Pesan test berhasil terkirim ke WhatsApp via Weagate!',
            default => 'Test notifikasi berhasil dikirimkan!',
        };

        return response()->json([
            'success' => $isSuccess,
            'channel' => $channel,
            'telegram' => $res['telegram'],
            'whatsapp' => $res['whatsapp'],
            'message' => $isSuccess ? $successMsg : 'Gagal mengirim test notifikasi',
            'target_phone' => $setting->whatsapp_target_phone,
            'chat_id' => $setting->telegram_chat_id,
            'errors' => $res['errors'],
        ]);
    }

    private function ensureAuthorized(Request $request): void
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $isAllowed = $user->isSuperAdmin()
            || $user->hasRole(Permissions::ROLE_SUPER_ADMIN)
            || $user->hasRole(Permissions::ROLE_ORG_ADMIN);

        if (! $isAllowed) {
            abort(403, 'Akses ditolak: Hanya Administrator yang berhak mengelola notifikasi.');
        }
    }
}

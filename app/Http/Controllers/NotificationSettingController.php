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
                'whatsapp_api_url' => $setting->whatsapp_api_url ?? '',
                'has_whatsapp_token' => ! empty($setting->whatsapp_api_token),
                'whatsapp_target_phone' => $setting->whatsapp_target_phone ?? '',
                'notify_router_down' => (bool) $setting->notify_router_down,
                'notify_router_recovery' => (bool) $setting->notify_router_recovery,
                'notify_high_cpu' => (bool) $setting->notify_high_cpu,
                'high_cpu_threshold' => (int) $setting->high_cpu_threshold,
                'notify_daily_digest' => (bool) $setting->notify_daily_digest,
                'notify_backup_completion' => (bool) $setting->notify_backup_completion,
                'notify_security_alert' => (bool) $setting->notify_security_alert,
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
            'whatsapp_target_phone' => ['nullable', 'string', 'max:32'],
            'notify_router_down' => ['boolean'],
            'notify_router_recovery' => ['boolean'],
            'notify_high_cpu' => ['boolean'],
            'high_cpu_threshold' => ['integer', 'between:50,99'],
            'notify_daily_digest' => ['boolean'],
            'notify_backup_completion' => ['boolean'],
            'notify_security_alert' => ['boolean'],
        ]);

        $org = $request->user()->organization;
        $setting = NotificationSetting::firstOrNew(['organization_id' => $org->id]);

        if (! empty($data['telegram_bot_token'])) {
            $setting->telegram_bot_token = $data['telegram_bot_token'];
        }
        $setting->telegram_chat_id = $data['telegram_chat_id'] ?? null;

        $setting->whatsapp_api_url = $data['whatsapp_api_url'] ?? null;
        if (! empty($data['whatsapp_api_token'])) {
            $setting->whatsapp_api_token = $data['whatsapp_api_token'];
        }
        $setting->whatsapp_target_phone = $data['whatsapp_target_phone'] ?? null;

        $setting->notify_router_down = (bool) ($data['notify_router_down'] ?? true);
        $setting->notify_router_recovery = (bool) ($data['notify_router_recovery'] ?? true);
        $setting->notify_high_cpu = (bool) ($data['notify_high_cpu'] ?? true);
        $setting->high_cpu_threshold = (int) ($data['high_cpu_threshold'] ?? 90);
        $setting->notify_daily_digest = (bool) ($data['notify_daily_digest'] ?? true);
        $setting->notify_backup_completion = (bool) ($data['notify_backup_completion'] ?? true);
        $setting->notify_security_alert = (bool) ($data['notify_security_alert'] ?? true);

        $setting->save();

        return back()->with('success', 'Pengaturan notifikasi berhasil disimpan.');
    }

    public function test(Request $request, NotificationDispatcher $dispatcher): JsonResponse
    {
        $this->ensureAuthorized($request);

        $org = $request->user()->organization;
        $timeStr = now()->format('d M Y H:i:s T');
        $testMsg = "<b>🔔 Test Notifikasi MikWeb</b>\n\n"
            ."Koneksi bot notifikasi berhasil terhubung!\n"
            ."Waktu: <code>{$timeStr}</code>\n"
            ."Organisasi: <b>{$org->name}</b>\n\n"
            .'<i>MikWeb MikroTik Fleet Management</i>';

        $res = $dispatcher->send($org, $testMsg);

        return response()->json([
            'success' => $res['telegram'] || $res['whatsapp'],
            'telegram' => $res['telegram'],
            'whatsapp' => $res['whatsapp'],
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

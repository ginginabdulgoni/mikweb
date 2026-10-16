<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use App\Domain\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationSetting extends Model
{
    protected $fillable = [
        'organization_id',
        'telegram_bot_token',
        'telegram_chat_id',
        'whatsapp_api_url',
        'whatsapp_api_token',
        'whatsapp_target_phone',
        'whatsapp_device_id',
        'notify_router_down',
        'notify_router_recovery',
        'notify_high_cpu',
        'high_cpu_threshold',
        'notify_daily_digest',
        'notify_backup_completion',
        'notify_security_alert',
        'template_router_down',
        'template_router_recovery',
        'template_high_cpu',
        'template_daily_digest',
    ];

    protected function casts(): array
    {
        return [
            'telegram_bot_token' => 'encrypted',
            'whatsapp_api_token' => 'encrypted',
            'notify_router_down' => 'boolean',
            'notify_router_recovery' => 'boolean',
            'notify_high_cpu' => 'boolean',
            'high_cpu_threshold' => 'integer',
            'notify_daily_digest' => 'boolean',
            'notify_backup_completion' => 'boolean',
            'notify_security_alert' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function hasTelegram(): bool
    {
        return ! empty($this->telegram_bot_token) && ! empty($this->telegram_chat_id);
    }

    public function hasWhatsApp(): bool
    {
        return (! empty($this->whatsapp_api_token) || ! empty($this->whatsapp_api_url)) && ! empty($this->whatsapp_target_phone);
    }

    public static function defaultTemplate(string $eventType): string
    {
        return match ($eventType) {
            'router_down' => "🚨 <b>ALERT: ROUTER TERPUTUS (DOWN)</b>\n\n"
                ."Router: <b>{router_name}</b>\n"
                ."Host / IP: <code>{router_host}</code>\n"
                ."Waktu: <code>{time}</code>\n"
                ."Error: <i>{error}</i>\n\n"
                .'<i>Segera periksa koneksi fisik, power supply, atau upstream ISP.</i>',

            'router_recovery' => "✅ <b>RECOVERY: ROUTER KEMBALI ONLINE</b>\n\n"
                ."Router: <b>{router_name}</b>\n"
                ."Host / IP: <code>{router_host}</code>\n"
                ."Waktu: <code>{time}</code>\n"
                ."Status: Normal (Reachable)\n\n"
                .'<i>Koneksi ke router telah pulih kembali.</i>',

            'high_cpu' => "⚠️ <b>WARNING: BEBAN CPU TINGGI</b>\n\n"
                ."Router: <b>{router_name}</b> ({router_host})\n"
                ."CPU Load: <b>{cpu_load}%</b> (Threshold: {threshold}%)\n"
                ."Memory: <b>{mem_percent}%</b>\n"
                ."Waktu: <code>{time}</code>\n\n"
                .'<i>Periksa rule firewall, loop interface, atau proses mencurigakan via MikWeb.</i>',

            'daily_digest' => "📋 <b>RINGKASAN HARIAN ARMADA MIKROTIK ({date})</b>\n\n"
                ."Organisasi: <b>{org_name}</b>\n"
                ."• Total Router: <b>{total_routers}</b> unit\n"
                ."• Online: 🟢 <b>{online_routers}</b>\n"
                ."• Terputus (Down): 🔴 <b>{down_routers}</b>\n"
                ."• Rata-rata CPU 24 Jam: <b>{avg_cpu}%</b>\n"
                ."• Rata-rata Latensi 24 Jam: <b>{avg_latency} ms</b>\n\n"
                .'<i>Laporan otomatis harian MikWeb</i>',

            default => '{message}',
        };
    }

    public function renderTemplate(string $eventType, array $variables = []): string
    {
        $raw = match ($eventType) {
            'router_down' => $this->template_router_down ?: self::defaultTemplate('router_down'),
            'router_recovery' => $this->template_router_recovery ?: self::defaultTemplate('router_recovery'),
            'high_cpu' => $this->template_high_cpu ?: self::defaultTemplate('high_cpu'),
            'daily_digest' => $this->template_daily_digest ?: self::defaultTemplate('daily_digest'),
            default => self::defaultTemplate($eventType),
        };

        foreach ($variables as $key => $val) {
            $raw = str_replace('{'.$key.'}', (string) $val, $raw);
        }

        return $raw;
    }
}

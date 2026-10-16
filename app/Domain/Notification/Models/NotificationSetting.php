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
        'notify_router_down',
        'notify_router_recovery',
        'notify_high_cpu',
        'high_cpu_threshold',
        'notify_daily_digest',
        'notify_backup_completion',
        'notify_security_alert',
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
        return ! empty($this->whatsapp_api_url) && ! empty($this->whatsapp_target_phone);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Domain\Notification\Models\NotificationSetting;
use App\Domain\Organization\Models\Organization;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationDispatcher
{
    /**
     * Send an alert message to all configured notification channels for an organization.
     *
     * @param  string|null  $alertType  e.g. 'router_down', 'router_recovery', 'high_cpu', 'daily_digest', 'backup', 'security'
     * @return array{telegram: bool, whatsapp: bool, errors: array<string>}
     */
    public function send(Organization $org, string $message, ?string $alertType = null): array
    {
        $setting = NotificationSetting::firstWhere('organization_id', $org->id);
        if (! $setting) {
            return ['telegram' => false, 'whatsapp' => false, 'errors' => ['No notification settings found']];
        }

        // Check toggle if alert type specified
        if ($alertType !== null && ! $this->isAlertEnabled($setting, $alertType)) {
            return ['telegram' => false, 'whatsapp' => false, 'errors' => ["Alert type '{$alertType}' is disabled"]];
        }

        $result = ['telegram' => false, 'whatsapp' => false, 'errors' => []];

        // 1. Telegram Dispatch
        if ($setting->hasTelegram()) {
            try {
                $botToken = $setting->telegram_bot_token;
                $chatId = $setting->telegram_chat_id;

                $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
                $res = Http::timeout(8)->post($url, [
                    'chat_id' => $chatId,
                    'text' => $message,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]);

                if ($res->successful()) {
                    $result['telegram'] = true;
                } else {
                    $err = 'Telegram API Error: '.$res->body();
                    $result['errors'][] = $err;
                    Log::warning($err);
                }
            } catch (\Throwable $e) {
                $err = 'Telegram Exception: '.$e->getMessage();
                $result['errors'][] = $err;
                Log::warning($err);
            }
        }

        // 2. WhatsApp Webhook Dispatch
        if ($setting->hasWhatsApp()) {
            try {
                $apiUrl = $setting->whatsapp_api_url;
                $apiToken = $setting->whatsapp_api_token;
                $targetPhone = $setting->whatsapp_target_phone;

                // Strip HTML tags for plain text WhatsApp message
                $plainText = strip_tags(str_replace(['<br>', '<br/>', '</p>'], "\n", $message));

                $headers = ['Content-Type' => 'application/json'];
                if ($apiToken) {
                    $headers['Authorization'] = $apiToken;
                }

                // Generic WhatsApp payload (compatible with Fonnte / Wablas / custom gateways)
                $payload = [
                    'target' => $targetPhone,
                    'phone' => $targetPhone,
                    'receiver' => $targetPhone,
                    'message' => $plainText,
                ];

                $res = Http::withHeaders($headers)->timeout(8)->post($apiUrl, $payload);
                if ($res->successful()) {
                    $result['whatsapp'] = true;
                } else {
                    $err = 'WhatsApp API Error: '.$res->body();
                    $result['errors'][] = $err;
                    Log::warning($err);
                }
            } catch (\Throwable $e) {
                $err = 'WhatsApp Exception: '.$e->getMessage();
                $result['errors'][] = $err;
                Log::warning($err);
            }
        }

        return $result;
    }

    private function isAlertEnabled(NotificationSetting $setting, string $alertType): bool
    {
        return match ($alertType) {
            'router_down' => $setting->notify_router_down,
            'router_recovery' => $setting->notify_router_recovery,
            'high_cpu' => $setting->notify_high_cpu,
            'daily_digest' => $setting->notify_daily_digest,
            'backup' => $setting->notify_backup_completion,
            'security' => $setting->notify_security_alert,
            default => true,
        };
    }
}

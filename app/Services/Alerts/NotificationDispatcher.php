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
     * Send an alert message to configured notification channels for an organization.
     *
     * @param  string|null  $alertType  e.g. 'router_down', 'router_recovery', 'high_cpu', 'daily_digest', 'backup', 'security'
     * @param  array<string, mixed>  $variables  Variables used to replace placeholders in custom alert templates
     * @param  string|null  $channel  Filter channel: 'telegram', 'whatsapp', or null/'all'
     * @param  NotificationSetting|null  $setting  Optional pre-loaded or in-memory setting override
     * @return array{telegram: bool, whatsapp: bool, errors: array<string>}
     */
    public function send(
        Organization $org,
        string $message,
        ?string $alertType = null,
        array $variables = [],
        ?string $channel = null,
        ?NotificationSetting $setting = null
    ): array {
        $setting = $setting ?? NotificationSetting::firstWhere('organization_id', $org->id);
        if (! $setting) {
            return ['telegram' => false, 'whatsapp' => false, 'errors' => ['No notification settings found']];
        }

        // Check toggle if alert type specified
        if ($alertType !== null && ! $this->isAlertEnabled($setting, $alertType)) {
            return ['telegram' => false, 'whatsapp' => false, 'errors' => ["Alert type '{$alertType}' is disabled"]];
        }

        // If alert type and variables are provided, render message using setting template
        if ($alertType !== null && ! empty($variables)) {
            $message = $setting->renderTemplate($alertType, $variables);
        }

        $result = ['telegram' => false, 'whatsapp' => false, 'errors' => []];

        $shouldSendTelegram = ($channel === null || $channel === 'all' || $channel === 'telegram') && $setting->hasTelegram();
        $shouldSendWhatsApp = ($channel === null || $channel === 'all' || $channel === 'whatsapp') && $setting->hasWhatsApp();

        // 1. Telegram Dispatch
        if ($shouldSendTelegram) {
            try {
                $botToken = $setting->telegram_bot_token;
                $chatId = $setting->telegram_chat_id;

                $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
                $res = Http::timeout(10)->post($url, [
                    'chat_id' => $chatId,
                    'text' => $message,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]);

                $json = $res->json();
                if ($res->successful() && ($json['ok'] ?? false) === true) {
                    $result['telegram'] = true;
                } else {
                    $desc = is_array($json) ? ($json['description'] ?? $res->body()) : ($res->body() ?: 'Gagal terhubung ke Telegram API');
                    $err = 'Telegram API Error: '.$desc;
                    $result['errors'][] = $err;
                    Log::warning($err);
                }
            } catch (\Throwable $e) {
                $err = 'Telegram Exception: '.$e->getMessage();
                $result['errors'][] = $err;
                Log::warning($err);
            }
        }

        // 2. WhatsApp Dispatch (Weagate / REST Gateway)
        if ($shouldSendWhatsApp) {
            try {
                $domain = trim($setting->whatsapp_api_url ?? '');
                if (empty($domain)) {
                    $domain = 'https://mywifi.weagate.com';
                }
                $endpoint = str_ends_with($domain, '/api/send-message')
                    ? $domain
                    : rtrim($domain, '/').'/api/send-message';

                $apiToken = $setting->whatsapp_api_token;
                $targetPhone = $this->normalizePhone($setting->whatsapp_target_phone ?? '');
                $deviceId = trim((string) ($setting->whatsapp_device_id ?? ''));

                $plainText = $this->formatForWhatsApp($message);

                $payload = array_filter([
                    'token' => $apiToken,
                    'to' => $targetPhone,
                    'type' => 'text',
                    'message' => $plainText,
                    'id_devices' => $deviceId ?: null,
                    'sender' => $deviceId ?: null,
                    'instan' => '1',
                ], static fn ($value) => $value !== null && $value !== '');

                // Primary attempt via asForm
                $res = Http::timeout(15)
                    ->acceptJson()
                    ->asForm()
                    ->post($endpoint, $payload);

                // Fallback attempt via JSON if 4xx/5xx
                if (! $res->successful()) {
                    $res = Http::timeout(15)
                        ->acceptJson()
                        ->post($endpoint, $payload);
                }

                $rawBody = $res->body();
                $json = $res->json();
                $isSuccess = false;

                if ($res->successful()) {
                    if (is_array($json)) {
                        $statusVal = $json['status'] ?? ($json['success'] ?? ($json['code'] ?? null));
                        $msgVal = (string) ($json['message'] ?? ($json['detail'] ?? ''));

                        // 1. Boolean check (handles true, 'true', 1, '1')
                        if ($statusVal === true || filter_var($statusVal, FILTER_VALIDATE_BOOLEAN) === true) {
                            $isSuccess = true;
                        }
                        // 2. String status keywords
                        elseif (is_string($statusVal) && in_array(strtoupper(trim($statusVal)), ['SUCCESS', 'TRUE', 'OK', 'SENT', 'BERHASIL', '200', '1'], true)) {
                            $isSuccess = true;
                        }
                        // 3. Integer status codes
                        elseif ($statusVal === 200 || $statusVal === 1) {
                            $isSuccess = true;
                        }
                        // 4. Message content indicator (e.g. "Pesan berhasil dikirim")
                        elseif ($msgVal !== '' && (
                            str_contains(strtolower($msgVal), 'berhasil')
                            || str_contains(strtolower($msgVal), 'success')
                            || str_contains(strtolower($msgVal), 'terkirim')
                            || str_contains(strtolower($msgVal), 'sent')
                        )) {
                            $isSuccess = true;
                        }
                    } else {
                        // Plain text response check
                        $lowerBody = strtolower(trim($rawBody));
                        if (str_contains($lowerBody, 'berhasil') || str_contains($lowerBody, 'success') || str_contains($lowerBody, 'terkirim') || str_contains($lowerBody, 'sent')) {
                            $isSuccess = true;
                        }
                    }
                }

                if ($isSuccess) {
                    $result['whatsapp'] = true;
                } else {
                    $msg = is_array($json)
                        ? ($json['message'] ?? ($json['detail'] ?? ($rawBody ?: 'Gagal mengirim pesan via Weagate')))
                        : ($rawBody ?: 'Gagal mengirim pesan via Weagate');
                    $err = 'WhatsApp Weagate Error: '.$msg;
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

    public function normalizePhone(string $phone): string
    {
        $trimmed = trim($phone);
        if (str_contains($trimmed, '@')) {
            return $trimmed;
        }

        $digits = preg_replace('/\D/', '', $trimmed);
        if (str_starts_with($digits, '08')) {
            return '628'.substr($digits, 2);
        }

        return $digits;
    }

    public function formatForWhatsApp(string $message): string
    {
        $formatted = preg_replace('/<b[^>]*>(.*?)<\/b>/is', '*$1*', $message);
        $formatted = preg_replace('/<strong[^>]*>(.*?)<\/strong>/is', '*$1*', $formatted);
        $formatted = preg_replace('/<i[^>]*>(.*?)<\/i>/is', '_$1_', $formatted);
        $formatted = preg_replace('/<em[^>]*>(.*?)<\/em>/is', '_$1_', $formatted);
        $formatted = preg_replace('/<code[^>]*>(.*?)<\/code>/is', '`$1`', $formatted);
        $formatted = preg_replace('/<pre[^>]*>(.*?)<\/pre>/is', "```\n$1\n```", $formatted);
        $formatted = str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $formatted);

        return trim(strip_tags($formatted));
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

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Monitoring\Models\RouterMetric;
use App\Domain\Router\Enums\RouterStatus;
use App\Domain\Router\Models\Router;
use App\Services\Alerts\NotificationDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CheckRouterFleetHealthCommand extends Command
{
    protected $signature = 'fleet:check-health';

    protected $description = 'Checks fleet router status transitions and CPU load, dispatching alerts via Telegram/WhatsApp.';

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $routers = Router::query()
            ->with(['organization'])
            ->where('monitoring_enabled', true)
            ->get();

        foreach ($routers as $router) {
            $org = $router->organization;
            if (! $org) {
                continue;
            }

            $cacheKeyStatus = "fleet:router:status:{$router->id}";
            $previousStatus = Cache::get($cacheKeyStatus, $router->status->value);
            $currentStatus = $router->status->value;

            // 1. Check DOWN transition
            if ($currentStatus === RouterStatus::Unreachable->value && $previousStatus !== RouterStatus::Unreachable->value) {
                $timeStr = now()->format('d M Y H:i:s T');
                $err = $router->last_error ?: 'Koneksi ke host terputus / unreachable';
                $msg = "🚨 <b>ALERT: ROUTER TERPUTUS (DOWN)</b>\n\n"
                    ."Router: <b>{$router->name}</b>\n"
                    ."Host / IP: <code>{$router->host}</code>\n"
                    ."Waktu: <code>{$timeStr}</code>\n"
                    ."Error: <i>{$err}</i>\n\n"
                    .'<i>Segera periksa koneksi fisik, power supply, atau upstream ISP.</i>';

                $dispatcher->send($org, $msg, 'router_down');
                $this->info("Dispatched DOWN alert for {$router->name}");
            }

            // 2. Check RECOVERY transition
            if ($currentStatus === RouterStatus::Online->value && $previousStatus === RouterStatus::Unreachable->value) {
                $timeStr = now()->format('d M Y H:i:s T');
                $msg = "✅ <b>RECOVERY: ROUTER KEMBALI ONLINE</b>\n\n"
                    ."Router: <b>{$router->name}</b>\n"
                    ."Host / IP: <code>{$router->host}</code>\n"
                    ."Waktu: <code>{$timeStr}</code>\n"
                    ."Status: Normal (Reachable)\n\n"
                    .'<i>Koneksi ke router telah pulih kembali.</i>';

                $dispatcher->send($org, $msg, 'router_recovery');
                $this->info("Dispatched RECOVERY alert for {$router->name}");
            }

            Cache::put($cacheKeyStatus, $currentStatus, now()->addHours(24));

            // 3. Check High CPU Load Alert
            $latestMetric = RouterMetric::query()
                ->where('router_id', $router->id)
                ->latest('id')
                ->first();

            if ($latestMetric && $latestMetric->cpu_load !== null && $router->status === RouterStatus::Online) {
                $threshold = (int) (optional($org->notificationSetting)->high_cpu_threshold ?: 90);
                if ($latestMetric->cpu_load >= $threshold) {
                    $throttleKey = "fleet:alert:high_cpu:{$router->id}";
                    if (! Cache::has($throttleKey)) {
                        $timeStr = now()->format('d M Y H:i:s T');
                        $msg = "⚠️ <b>WARNING: HIGH CPU LOAD</b>\n\n"
                            ."Router: <b>{$router->name}</b> ({$router->host})\n"
                            ."CPU Load: <b>{$latestMetric->cpu_load}%</b> (Threshold: {$threshold}%)\n"
                            ."Memory: <b>{$latestMetric->memoryPercent()}%</b>\n"
                            ."Waktu: <code>{$timeStr}</code>\n\n"
                            .'<i>Periksa rule firewall, loop interface, atau proses mencurigakan via MikWeb Tools / Profiler.</i>';

                        $dispatcher->send($org, $msg, 'high_cpu');
                        // Throttle alert for 15 minutes to avoid spamming
                        Cache::put($throttleKey, true, now()->addMinutes(15));
                        $this->info("Dispatched HIGH CPU alert for {$router->name}");
                    }
                }
            }
        }

        return self::SUCCESS;
    }
}

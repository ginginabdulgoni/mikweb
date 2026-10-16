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
                $vars = [
                    'router_name' => $router->name,
                    'router_host' => $router->host,
                    'time' => $timeStr,
                    'date' => now()->format('d M Y'),
                    'error' => $err,
                    'status' => 'Unreachable',
                    'org_name' => $org->name,
                ];

                $dispatcher->send($org, '', 'router_down', $vars);
                $this->info("Dispatched DOWN alert for {$router->name}");
            }

            // 2. Check RECOVERY transition
            if ($currentStatus === RouterStatus::Online->value && $previousStatus === RouterStatus::Unreachable->value) {
                $timeStr = now()->format('d M Y H:i:s T');
                $vars = [
                    'router_name' => $router->name,
                    'router_host' => $router->host,
                    'time' => $timeStr,
                    'date' => now()->format('d M Y'),
                    'status' => 'Online',
                    'org_name' => $org->name,
                ];

                $dispatcher->send($org, '', 'router_recovery', $vars);
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
                        $vars = [
                            'router_name' => $router->name,
                            'router_host' => $router->host,
                            'cpu_load' => (string) $latestMetric->cpu_load,
                            'threshold' => (string) $threshold,
                            'mem_percent' => (string) $latestMetric->memoryPercent(),
                            'time' => $timeStr,
                            'date' => now()->format('d M Y'),
                            'org_name' => $org->name,
                        ];

                        $dispatcher->send($org, '', 'high_cpu', $vars);
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

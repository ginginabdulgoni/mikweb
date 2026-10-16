<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Monitoring\Models\RouterMetric;
use App\Domain\Organization\Models\Organization;
use App\Domain\Router\Enums\RouterStatus;
use App\Domain\Router\Models\Router;
use App\Services\Alerts\NotificationDispatcher;
use Illuminate\Console\Command;

class SendDailyDigestCommand extends Command
{
    protected $signature = 'fleet:daily-digest';

    protected $description = 'Sends daily fleet health summary digest via Telegram and WhatsApp.';

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $orgs = Organization::all();

        foreach ($orgs as $org) {
            $routers = Router::where('organization_id', $org->id)->get();
            if ($routers->isEmpty()) {
                continue;
            }

            $total = $routers->count();
            $online = $routers->where('status', RouterStatus::Online)->count();
            $down = $routers->where('status', RouterStatus::Unreachable)->count();

            $metrics = RouterMetric::whereIn('router_id', $routers->pluck('id'))
                ->where('recorded_at', '>=', now()->subHours(24))
                ->get();

            $avgCpu = $metrics->isNotEmpty() ? round($metrics->avg('cpu_load')) : 0;
            $avgLatency = $metrics->isNotEmpty() ? round($metrics->avg('latency_ms')) : 0;

            $dateStr = now()->format('d M Y');
            $vars = [
                'org_name' => $org->name,
                'total_routers' => (string) $total,
                'online_routers' => (string) $online,
                'down_routers' => (string) $down,
                'avg_cpu' => (string) $avgCpu,
                'avg_latency' => (string) $avgLatency,
                'date' => $dateStr,
                'time' => now()->format('d M Y H:i:s T'),
            ];

            $dispatcher->send($org, '', 'daily_digest', $vars);
            $this->info("Sent daily digest for {$org->name}");
        }

        return self::SUCCESS;
    }
}

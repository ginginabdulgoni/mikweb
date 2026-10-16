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
            $msg = "📋 <b>RINGKASAN HARIAN ARMADA MIKROTIK ({$dateStr})</b>\n\n"
                ."Organisasi: <b>{$org->name}</b>\n"
                ."• Total Router: <b>{$total}</b> unit\n"
                ."• Online: 🟢 <b>{$online}</b>\n"
                ."• Terputus (Down): 🔴 <b>{$down}</b>\n"
                ."• Rata-rata CPU 24 Jam: <b>{$avgCpu}%</b>\n"
                ."• Rata-rata Latensi 24 Jam: <b>{$avgLatency} ms</b>\n\n"
                .'Dashboard: <a href="'.config('app.url')."/monitoring\">Buka MikWeb Monitoring</a>\n"
                .'<i>Laporan otomatis harian MikWeb</i>';

            $dispatcher->send($org, $msg, 'daily_digest');
            $this->info("Sent daily digest for {$org->name}");
        }

        return self::SUCCESS;
    }
}

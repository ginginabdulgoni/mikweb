<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Monitoring\Actions\CollectRouterMetrics;
use App\Domain\Router\Models\Router;
use App\Jobs\CollectRouterMetricsJob;
use Illuminate\Console\Command;

class CollectMetricsCommand extends Command
{
    protected $signature = 'mikweb:collect-metrics {--sync : Run inline instead of dispatching to the queue}';

    protected $description = 'Poll monitored routers for resource metrics';

    public function handle(CollectRouterMetrics $collector): int
    {
        $routers = Router::query()->where('monitoring_enabled', true)->get();

        $this->info("Collecting metrics for {$routers->count()} router(s)…");

        foreach ($routers as $router) {
            if ($this->option('sync')) {
                $metric = $collector->execute($router);
                $this->line("  #{$router->id} {$router->name}: ".($metric->reachable ? "cpu {$metric->cpu_load}% mem {$metric->memoryPercent()}%" : 'unreachable'));
            } else {
                CollectRouterMetricsJob::dispatch($router->id);
            }
        }

        return self::SUCCESS;
    }
}

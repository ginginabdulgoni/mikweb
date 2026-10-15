<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Monitoring\Actions\CollectRouterMetrics;
use App\Domain\Router\Models\Router;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Queued per-router metrics poll. Dispatched by the scheduler so monitoring
 * work never blocks web requests, and one router's slowness can't stall others.
 */
class CollectRouterMetricsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $routerId) {}

    /** Never poll the same router concurrently. */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->routerId))->dontRelease()];
    }

    public function handle(CollectRouterMetrics $collector): void
    {
        $router = Router::find($this->routerId);
        if ($router === null) {
            return;
        }

        $collector->execute($router);
    }
}

<?php

declare(strict_types=1);

use App\Jobs\EvaluateAutomationJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── MikWeb scheduled work ────────────────────────────────────────────
// Metrics polling. Interval is intentionally coarse here; per-router poll
// cadence can be refined later with adaptive scheduling.
Schedule::command('mikweb:collect-metrics')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Automation rule evaluation (offline/high-CPU/etc → notifications).
Schedule::job(new EvaluateAutomationJob)
    ->everyFiveMinutes()
    ->name('mikweb-automation')
    ->withoutOverlapping();

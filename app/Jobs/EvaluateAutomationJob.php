<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Automation\AutomationEngine;
use App\Domain\Organization\Models\Organization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scheduled evaluation of every organisation's automation rules.
 */
class EvaluateAutomationJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public function handle(AutomationEngine $engine): void
    {
        Organization::query()->pluck('id')->each(
            fn ($id) => $engine->evaluateOrganization((int) $id),
        );
    }
}

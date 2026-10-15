<?php

declare(strict_types=1);

namespace App\Domain\Automation;

use App\Domain\Automation\Models\AutomationExecution;
use App\Domain\Automation\Models\AutomationRule;
use App\Domain\Monitoring\Models\RouterMetric;
use App\Domain\Router\Enums\RouterStatus;
use App\Domain\Router\Models\Router;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Evaluates automation rules against current fleet state and fires the rule's
 * action when its trigger matches. Triggers are evaluated from data MikWeb
 * already holds (router status, latest metrics) so evaluation is cheap and
 * never hammers devices.
 */
class AutomationEngine
{
    /** @return array<string, int> summary of executions per rule */
    public function evaluateOrganization(int $organizationId): array
    {
        $rules = AutomationRule::where('organization_id', $organizationId)->where('enabled', true)->get();
        $summary = [];

        foreach ($rules as $rule) {
            $summary[$rule->name] = $this->evaluate($rule);
        }

        return $summary;
    }

    public function evaluate(AutomationRule $rule): int
    {
        $matched = $this->matchingRouters($rule);

        foreach ($matched as $router) {
            $this->fire($rule, $router);
        }

        $rule->forceFill([
            'last_evaluated_at' => now(),
            'last_triggered_at' => $matched->isNotEmpty() ? now() : $rule->last_triggered_at,
        ])->save();

        return $matched->count();
    }

    /** @return Collection<int, Router> */
    private function matchingRouters(AutomationRule $rule)
    {
        $routers = Router::where('organization_id', $rule->organization_id)->get();

        return match ($rule->trigger) {
            'router_offline', 'router_unreachable' => $routers->filter(
                fn (Router $r) => in_array($r->status, [RouterStatus::Offline, RouterStatus::Unreachable], true),
            ),
            'cpu_high' => $routers->filter(function (Router $r) use ($rule) {
                $threshold = (int) ($rule->conditions['threshold'] ?? 80);
                $latest = RouterMetric::where('router_id', $r->id)->latest('id')->first();

                return $latest && $latest->cpu_load !== null && $latest->cpu_load >= $threshold;
            }),
            default => $routers->take(0),
        };
    }

    private function fire(AutomationRule $rule, Router $router): void
    {
        $message = $this->message($rule, $router);

        try {
            match ($rule->action) {
                'notify_webhook' => $this->webhook($rule, $message),
                'notify_telegram' => $this->telegram($rule, $message),
                default => Log::channel('stack')->warning('[MikWeb automation] '.$message),
            };
            $status = 'triggered';
            $logMsg = $message;
        } catch (\Throwable $e) {
            $status = 'error';
            $logMsg = 'Action failed: '.$e->getMessage();
        }

        AutomationExecution::create([
            'automation_rule_id' => $rule->id,
            'router_id' => $router->id,
            'status' => $status,
            'message' => mb_substr($logMsg, 0, 1000),
            'created_at' => now(),
        ]);
    }

    private function message(AutomationRule $rule, Router $router): string
    {
        return match ($rule->trigger) {
            'cpu_high' => "Router {$router->name} ({$router->host}) CPU is high.",
            default => "Router {$router->name} ({$router->host}) is {$router->status->value}.",
        };
    }

    private function webhook(AutomationRule $rule, string $message): void
    {
        $url = $rule->action_params['url'] ?? null;
        if ($url) {
            Http::timeout(5)->post($url, ['text' => $message, 'rule' => $rule->name]);
        }
    }

    private function telegram(AutomationRule $rule, string $message): void
    {
        $token = $rule->action_params['bot_token'] ?? null;
        $chatId = $rule->action_params['chat_id'] ?? null;
        if ($token && $chatId) {
            Http::timeout(5)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => "🔔 {$message}",
            ]);
        }
    }
}

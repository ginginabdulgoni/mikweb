<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Automation\AutomationEngine;
use App\Domain\Automation\Models\AutomationExecution;
use App\Domain\Automation\Models\AutomationRule;
use App\Support\Access\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AutomationController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize(Permissions::AUTOMATION_VIEW);
        $orgId = $request->user()->organization_id;

        $rules = AutomationRule::where('organization_id', $orgId)->latest()->get()
            ->map(fn (AutomationRule $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'trigger' => $r->trigger,
                'action' => $r->action,
                'conditions' => $r->conditions,
                'enabled' => $r->enabled,
                'last_triggered_at' => $r->last_triggered_at?->diffForHumans(),
            ]);

        $executions = AutomationExecution::whereIn('automation_rule_id', $rules->pluck('id'))
            ->with('router:id,name')
            ->latest('created_at')->limit(30)->get()
            ->map(fn (AutomationExecution $e) => [
                'id' => $e->id,
                'status' => $e->status,
                'message' => $e->message,
                'router' => $e->router?->name,
                'created_at' => $e->created_at?->diffForHumans(),
            ]);

        return Inertia::render('Automation/Index', [
            'rules' => $rules,
            'executions' => $executions,
            'can' => ['manage' => $request->user()->can(Permissions::AUTOMATION_MANAGE)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize(Permissions::AUTOMATION_MANAGE);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'trigger' => ['required', 'in:router_offline,router_unreachable,cpu_high'],
            'action' => ['required', 'in:notify_log,notify_webhook,notify_telegram'],
            'threshold' => ['nullable', 'integer', 'between:1,100'],
            'webhook_url' => ['nullable', 'url', 'max:255'],
            'telegram_bot_token' => ['nullable', 'string', 'max:255'],
            'telegram_chat_id' => ['nullable', 'string', 'max:64'],
        ]);

        $rule = AutomationRule::create([
            'organization_id' => $request->user()->organization_id,
            'created_by' => $request->user()->id,
            'name' => $data['name'],
            'trigger' => $data['trigger'],
            'conditions' => $data['trigger'] === 'cpu_high' ? ['threshold' => $data['threshold'] ?? 80] : null,
            'action' => $data['action'],
            'action_params' => array_filter([
                'url' => $data['webhook_url'] ?? null,
                'bot_token' => $data['telegram_bot_token'] ?? null,
                'chat_id' => $data['telegram_chat_id'] ?? null,
            ]),
            'enabled' => true,
        ]);

        AuditLogger::record('automation.create', $rule, new: ['name' => $rule->name, 'trigger' => $rule->trigger]);

        return back()->with('success', "Automation rule \"{$rule->name}\" created.");
    }

    public function toggle(Request $request, AutomationRule $rule): RedirectResponse
    {
        $this->authorize(Permissions::AUTOMATION_MANAGE);
        $this->ensureOrg($request, $rule);

        $rule->forceFill(['enabled' => ! $rule->enabled])->save();

        return back()->with('success', $rule->enabled ? 'Rule enabled.' : 'Rule disabled.');
    }

    public function destroy(Request $request, AutomationRule $rule): RedirectResponse
    {
        $this->authorize(Permissions::AUTOMATION_MANAGE);
        $this->ensureOrg($request, $rule);

        AuditLogger::record('automation.delete', $rule, old: ['name' => $rule->name]);
        $rule->delete();

        return back()->with('success', 'Rule removed.');
    }

    public function evaluate(Request $request, AutomationEngine $engine): RedirectResponse
    {
        $this->authorize(Permissions::AUTOMATION_MANAGE);

        $summary = $engine->evaluateOrganization($request->user()->organization_id);
        $triggered = array_sum($summary);

        return back()->with('success', 'Evaluated '.count($summary)." rule(s); {$triggered} match(es) fired.");
    }

    private function ensureOrg(Request $request, AutomationRule $rule): void
    {
        abort_unless($rule->organization_id === $request->user()->organization_id, 403);
    }
}

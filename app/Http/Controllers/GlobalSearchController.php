<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Backup\Models\RouterBackup;
use App\Domain\Router\Models\Router;
use App\Domain\Router\Models\RouterGroup;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cross-router global search across routers, groups, backups, and audit logs.
 */
class GlobalSearchController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->string('q'));
        $orgId = $request->user()->organization_id;

        $routers = [];
        $groups = [];
        $backups = [];
        $auditLogs = [];

        if ($q !== '') {
            $routers = Router::where('organization_id', $orgId)
                ->where(fn ($query) => $query
                    ->where('name', 'like', "%{$q}%")
                    ->orWhere('host', 'like', "%{$q}%")
                    ->orWhere('identity', 'like', "%{$q}%")
                    ->orWhere('serial_number', 'like', "%{$q}%")
                    ->orWhere('hardware_model', 'like', "%{$q}%")
                    ->orWhere('site', 'like', "%{$q}%"))
                ->limit(25)->get()
                ->map(fn (Router $r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'host' => $r->host,
                    'status' => $r->status->value,
                    'status_color' => $r->status->color(),
                    'identity' => $r->identity,
                    'routeros_version' => $r->routeros_version,
                ])->values();

            $groups = RouterGroup::where('organization_id', $orgId)
                ->where('name', 'like', "%{$q}%")
                ->limit(10)->get(['id', 'name', 'type']);

            $backups = RouterBackup::query()
                ->whereHas('router', fn ($rq) => $rq->where('organization_id', $orgId))
                ->where(fn ($query) => $query
                    ->where('filename', 'like', "%{$q}%")
                    ->orWhere('notes', 'like', "%{$q}%")
                    ->orWhereHas('router', fn ($rq) => $rq->where('name', 'like', "%{$q}%")))
                ->with('router:id,name')
                ->latest('created_at')
                ->limit(15)->get()
                ->map(fn (RouterBackup $b) => [
                    'id' => $b->id,
                    'filename' => $b->filename,
                    'router_id' => $b->router_id,
                    'router_name' => $b->router?->name ?? 'Router',
                    'size' => $b->size,
                    'created_at' => $b->created_at?->diffForHumans(),
                ]);

            $auditLogs = AuditLog::query()
                ->where('organization_id', $orgId)
                ->where(fn ($query) => $query
                    ->where('action', 'like', "%{$q}%")
                    ->orWhere('ip_address', 'like', "%{$q}%")
                    ->orWhereHas('router', fn ($rq) => $rq->where('name', 'like', "%{$q}%")))
                ->with(['router:id,name', 'user:id,name'])
                ->latest('created_at')
                ->limit(15)->get()
                ->map(fn (AuditLog $a) => [
                    'id' => $a->id,
                    'action' => $a->action,
                    'user_name' => $a->user?->name ?? 'System',
                    'router_name' => $a->router?->name,
                    'router_id' => $a->router_id,
                    'ip_address' => $a->ip_address,
                    'created_at' => $a->created_at?->diffForHumans(),
                ]);
        }

        return Inertia::render('Search/Index', [
            'q' => $q,
            'routers' => $routers,
            'groups' => $groups,
            'backups' => $backups,
            'auditLogs' => $auditLogs,
        ]);
    }
}

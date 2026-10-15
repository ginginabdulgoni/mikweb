<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Backup\BackupService;
use App\Domain\Backup\Models\RouterBackup;
use App\Domain\Router\Data\RouterPresenter;
use App\Domain\Router\Models\Router;
use App\Services\RouterOS\Exceptions\RouterOsException;
use App\Support\Access\Permissions;
use App\Support\Access\RouterAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    public function index(Request $request, Router $router): Response
    {
        $this->authorize('view', $router);
        $this->requireModule($request, $router, Permissions::BACKUP_VIEW);
        $router->load('capability');

        $backups = RouterBackup::where('router_id', $router->id)
            ->latest('created_at')->limit(50)->get()
            ->map(fn (RouterBackup $b) => [
                'id' => $b->id,
                'type' => $b->type,
                'filename' => $b->filename,
                'size' => $b->size,
                'routeros_version' => $b->routeros_version,
                'created_at' => $b->created_at?->toDateTimeString(),
            ]);

        return Inertia::render('Routers/Modules/Backups', [
            'router' => RouterPresenter::header($router),
            'backups' => $backups,
            'can' => ['manage' => app(RouterAccess::class)->allows($request->user(), Permissions::BACKUP_MANAGE, $router)],
        ]);
    }

    public function export(Request $request, Router $router, BackupService $service): RedirectResponse
    {
        $this->authorize('view', $router);
        $this->requireModule($request, $router, Permissions::BACKUP_MANAGE);

        try {
            $backup = $service->export($router, $request->user()->id);
        } catch (RouterOsException $e) {
            return back()->with('error', 'Backup failed: '.$e->getMessage());
        }

        AuditLogger::record('backup.export', $router, new: ['filename' => $backup->filename], routerId: $router->id);

        return back()->with('success', "Configuration exported ({$backup->filename}).");
    }

    public function download(Request $request, RouterBackup $backup): StreamedResponse
    {
        $router = $backup->router;
        $this->authorize('view', $router);
        $this->requireModule($request, $router, Permissions::BACKUP_VIEW);

        abort_unless(Storage::disk($backup->disk)->exists($backup->path), 404);

        return Storage::disk($backup->disk)->download($backup->path, $backup->filename);
    }

    private function requireModule(Request $request, Router $router, string $ability): void
    {
        if (! app(RouterAccess::class)->allows($request->user(), $ability, $router)) {
            abort(403);
        }
    }
}

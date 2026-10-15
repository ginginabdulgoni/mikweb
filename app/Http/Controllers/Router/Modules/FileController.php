<?php

declare(strict_types=1);

namespace App\Http\Controllers\Router\Modules;

use App\Domain\Audit\AuditLogger;
use App\Domain\Router\Data\RouterPresenter;
use App\Domain\Router\Models\Router;
use App\Http\Controllers\Controller;
use App\Services\RouterOS\ResourceClient;
use App\Support\Access\Permissions;
use App\Support\Access\RouterAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * RouterOS Files Explorer (/file).
 * Lists router filesystem storage, creates backup files, and removes files.
 */
class FileController extends Controller
{
    use InteractsWithRouterModule;

    private const MENU = '/file';

    public function index(Request $request, Router $router, ResourceClient $client): Response
    {
        $this->ensureModuleAccess($router, Permissions::SYSTEM_VIEW);
        $router->load('capability');
        $uid = $request->user()->id;

        $raw = $this->deviceRead(fn () => $client->list($router, self::MENU, userId: $uid));

        $cleanFiles = array_map(function (mixed $file): array {
            if (! is_array($file)) {
                return [];
            }

            return [
                '.id' => (string) ($file['.id'] ?? ''),
                'name' => mb_convert_encoding((string) ($file['name'] ?? ''), 'UTF-8', 'UTF-8'),
                'type' => mb_convert_encoding((string) ($file['type'] ?? 'file'), 'UTF-8', 'UTF-8'),
                'size' => (string) ($file['size'] ?? '0'),
                'creation-time' => mb_convert_encoding((string) ($file['creation-time'] ?? ''), 'UTF-8', 'UTF-8'),
            ];
        }, $raw['data'] ?? []);

        return Inertia::render('Routers/Modules/Files', [
            'router' => RouterPresenter::header($router),
            'files' => array_values(array_filter($cleanFiles, fn ($f) => $f !== [])),
            'deviceError' => $raw['error'],
            'can' => ['manage' => app(RouterAccess::class)->allows($request->user(), Permissions::SYSTEM_MANAGE, $router)],
        ]);
    }

    public function createBackup(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::SYSTEM_MANAGE);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:64'],
            'password' => ['nullable', 'string', 'max:64'],
        ]);

        $name = filled($data['name'] ?? null) ? $data['name'] : 'backup-'.date('Ymd-His');
        $params = ['name' => $name];
        if (filled($data['password'] ?? null)) {
            $params['password'] = $data['password'];
        }

        return $this->deviceAction(function () use ($client, $router, $params, $name, $request) {
            $client->action($router, '/system/backup/save', $params, $request->user()->id);
            AuditLogger::record('router.file_backup_create', $router, new: ['name' => $name], routerId: $router->id);
        }, "Backup file '{$name}.backup' created on router storage.");
    }

    public function destroy(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::SYSTEM_MANAGE);
        $data = $request->validate([
            'id' => ['required', 'string'],
        ]);

        return $this->deviceAction(function () use ($client, $router, $data, $request) {
            $client->remove($router, self::MENU, $data['id'], $request->user()->id);
            AuditLogger::record('router.file_delete', $router, new: ['id' => $data['id']], routerId: $router->id);
        }, 'File removed from router storage.');
    }

    public function batch(Request $request, Router $router, ResourceClient $client): RedirectResponse
    {
        $this->ensureModuleAccess($router, Permissions::SYSTEM_MANAGE);
        $data = $request->validate([
            'action' => ['required', 'string', 'in:destroy'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'string'],
        ]);

        $ids = $data['ids'];
        $uid = $request->user()->id;

        return $this->deviceAction(function () use ($client, $router, $ids, $uid) {
            foreach ($ids as $id) {
                $client->remove($router, self::MENU, $id, $uid);
            }

            AuditLogger::record('router.file_batch_destroy', $router, new: [
                'count' => count($ids),
            ], routerId: $router->id);
        }, count($ids).' file berhasil dihapus dari router storage.');
    }
}

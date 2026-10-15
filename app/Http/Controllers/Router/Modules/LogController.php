<?php

declare(strict_types=1);

namespace App\Http\Controllers\Router\Modules;

use App\Domain\Router\Data\RouterPresenter;
use App\Domain\Router\Models\Router;
use App\Http\Controllers\Controller;
use App\Services\RouterOS\ResourceClient;
use App\Support\Access\Permissions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dedicated RouterOS Log window (/log).
 * Views live and historical device event log streams with topic filtering and message search.
 */
class LogController extends Controller
{
    use InteractsWithRouterModule;

    private const MENU = '/log';

    public function index(Request $request, Router $router, ResourceClient $client): Response
    {
        $this->ensureModuleAccess($router, Permissions::SYSTEM_VIEW);
        $router->load('capability');
        $uid = $request->user()->id;

        $logs = $this->deviceRead(fn () => $client->list($router, self::MENU, userId: $uid));

        return Inertia::render('Routers/Modules/Log', [
            'router' => RouterPresenter::header($router),
            'logs' => $logs['data'],
            'deviceError' => $logs['error'],
        ]);
    }
}

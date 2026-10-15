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
 * IP Neighbor discovery table (/ip/neighbor).
 * Shows devices discovered on the network via MNDP, CDP, and LLDP protocols.
 */
class NeighborController extends Controller
{
    use InteractsWithRouterModule;

    private const MENU = '/ip/neighbor';

    public function index(Request $request, Router $router, ResourceClient $client): Response
    {
        $this->ensureModuleAccess($router, Permissions::IP_VIEW);
        $router->load('capability');
        $uid = $request->user()->id;

        $neighbors = $this->deviceRead(fn () => $client->list($router, self::MENU, userId: $uid));

        return Inertia::render('Routers/Modules/Neighbors', [
            'router' => RouterPresenter::header($router),
            'neighbors' => $neighbors['data'],
            'deviceError' => $neighbors['error'],
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Router\Modules;

use App\Domain\Audit\AuditLogger;
use App\Domain\Router\Data\RouterPresenter;
use App\Domain\Router\Models\Router;
use App\Http\Controllers\Controller;
use App\Services\RouterOS\ConnectionManager;
use App\Services\RouterOS\Exceptions\RouterOsException;
use App\Support\Access\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Web Terminal / Interactive RouterOS Console.
 * Allows administrators to run RouterOS CLI commands directly from the browser.
 */
class TerminalController extends Controller
{
    use InteractsWithRouterModule;

    public function __construct(private readonly ConnectionManager $connectionManager) {}

    public function index(Request $request, Router $router): Response
    {
        $this->ensureModuleAccess($router, Permissions::ROUTER_MANAGE);
        $router->load('capability');

        return Inertia::render('Routers/Modules/Terminal', [
            'router' => RouterPresenter::header($router),
        ]);
    }

    public function execute(Request $request, Router $router): JsonResponse
    {
        $this->ensureModuleAccess($router, Permissions::ROUTER_MANAGE);
        $data = $request->validate([
            'command' => ['required', 'string', 'max:500'],
        ]);

        $rawCmd = trim($data['command']);
        $uid = $request->user()->id;

        // Strip leading slash or prefix if provided
        $clean = preg_replace('/\s+/', ' ', $rawCmd);
        $parts = explode(' ', (string) $clean);
        $path = $parts[0] ?? '';

        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        // Parse key=value parameters from remaining tokens
        $params = [];
        for ($i = 1; $i < count($parts); $i++) {
            $token = $parts[$i];
            if (str_contains($token, '=')) {
                [$k, $v] = explode('=', $token, 2);
                $params[trim($k)] = trim($v);
            } else {
                $params['arg_'.$i] = trim($token);
            }
        }

        try {
            $isPrint = str_ends_with($path, '/print') || ! str_contains($path, '/') || (! str_ends_with($path, '/set') && ! str_ends_with($path, '/add') && ! str_ends_with($path, '/remove') && ! str_ends_with($path, '/enable') && ! str_ends_with($path, '/disable') && ! str_ends_with($path, '/reboot'));

            if ($isPrint && ! str_ends_with($path, '/print') && ! str_starts_with($path, '/tool/') && ! str_starts_with($path, '/ping')) {
                $testPath = rtrim($path, '/').'/print';
            } else {
                $testPath = $path;
            }

            if (str_ends_with($testPath, '/print')) {
                $rows = $this->connectionManager->query($router, $testPath, $params, $uid);
            } else {
                $rows = $this->connectionManager->execute($router, $testPath, $params, $uid);
            }

            AuditLogger::record('router.terminal_command', $router, new: ['cmd' => $rawCmd], routerId: $router->id);

            $formatted = $this->formatOutput($rows);

            return response()->json([
                'success' => true,
                'output' => $formatted,
                'raw' => $rows,
            ]);
        } catch (RouterOsException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'Command execution failed: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function formatOutput(array $rows): string
    {
        if (empty($rows)) {
            return "ok\n";
        }

        // If returned item is a string return / message
        if (isset($rows[0]['ret']) && count($rows[0]) === 1) {
            return (string) $rows[0]['ret'];
        }

        $lines = [];
        foreach ($rows as $idx => $row) {
            $cols = [];
            foreach ($row as $k => $v) {
                if ($k === '.id') {
                    continue;
                }
                $cols[] = "{$k}={$v}";
            }
            $lines[] = sprintf('%2d  %s', $idx, implode('  ', $cols));
        }

        return implode("\n", $lines);
    }
}

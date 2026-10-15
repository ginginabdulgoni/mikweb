<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Domain\Backup\Models\RouterBackup;
use App\Domain\Router\Models\Router;
use App\Services\RouterOS\ResourceClient;
use Illuminate\Support\Facades\Storage;

/**
 * Captures a RouterOS text export (/export) through the API and stores it as a
 * versioned, downloadable backup file. Binary backups (/system/backup/save)
 * create a file on the device; fetching that file off the device is a separate
 * concern deferred to a later iteration (noted in the UI).
 */
class BackupService
{
    public function __construct(private readonly ResourceClient $client) {}

    public function export(Router $router, ?int $userId = null): RouterBackup
    {
        $router->loadMissing('credentials');

        $rows = $this->client->action($router, '/export', [], $userId);
        $text = $this->extractText($rows);

        $filename = sprintf('%s-%s.rsc', str($router->name)->slug(), now()->format('Ymd-His'));
        $path = "backups/{$router->id}/{$filename}";

        Storage::disk('local')->put($path, $text);

        return RouterBackup::create([
            'router_id' => $router->id,
            'created_by' => $userId,
            'type' => 'export',
            'disk' => 'local',
            'path' => $path,
            'filename' => $filename,
            'size' => strlen($text),
            'routeros_version' => $router->routeros_version,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function extractText(array $rows): string
    {
        // RouterOS may return the export body in a 'ret' field or as discrete
        // lines across rows; handle both.
        $lines = [];
        foreach ($rows as $row) {
            if (isset($row['ret'])) {
                $lines[] = (string) $row['ret'];
            } else {
                $lines[] = implode(' ', array_map('strval', $row));
            }
        }

        $text = trim(implode("\n", $lines));

        return $text !== '' ? $text."\n" : "# (device returned an empty export)\n";
    }
}

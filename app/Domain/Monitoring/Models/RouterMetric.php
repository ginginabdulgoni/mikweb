<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Models;

use App\Domain\Router\Models\Router;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouterMetric extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'router_id', 'cpu_load', 'mem_used', 'mem_total', 'disk_used', 'disk_total',
        'uptime', 'reachable', 'latency_ms', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'reachable' => 'boolean',
            'recorded_at' => 'datetime',
        ];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function memoryPercent(): ?int
    {
        if (! $this->mem_total) {
            return null;
        }

        return (int) round((($this->mem_used ?? 0) / $this->mem_total) * 100);
    }
}

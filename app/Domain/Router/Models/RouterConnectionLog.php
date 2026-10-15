<?php

declare(strict_types=1);

namespace App\Domain\Router\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouterConnectionLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'router_id', 'transport', 'event', 'success',
        'duration_ms', 'message', 'context', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }
}

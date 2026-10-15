<?php

declare(strict_types=1);

namespace App\Domain\Router\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouterCommandLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'router_id', 'user_id', 'transport', 'command', 'arguments',
        'success', 'duration_ms', 'result_count', 'error', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'success' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

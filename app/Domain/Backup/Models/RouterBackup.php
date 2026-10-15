<?php

declare(strict_types=1);

namespace App\Domain\Backup\Models;

use App\Domain\Router\Models\Router;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouterBackup extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'router_id', 'created_by', 'type', 'disk', 'path', 'filename',
        'size', 'routeros_version', 'notes', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

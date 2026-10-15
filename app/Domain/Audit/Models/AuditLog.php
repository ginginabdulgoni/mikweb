<?php

declare(strict_types=1);

namespace App\Domain\Audit\Models;

use App\Domain\Organization\Models\Organization;
use App\Domain\Router\Models\Router;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'organization_id', 'router_id', 'action',
        'auditable_type', 'auditable_id', 'ip_address', 'user_agent',
        'old_values', 'new_values', 'meta', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}

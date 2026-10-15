<?php

declare(strict_types=1);

namespace App\Domain\Automation\Models;

use App\Domain\Router\Models\Router;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationExecution extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['automation_rule_id', 'router_id', 'status', 'message', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }
}

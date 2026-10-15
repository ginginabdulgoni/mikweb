<?php

declare(strict_types=1);

namespace App\Domain\Automation\Models;

use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationRule extends Model
{
    protected $fillable = [
        'organization_id', 'created_by', 'name', 'trigger', 'conditions',
        'action', 'action_params', 'enabled', 'last_evaluated_at', 'last_triggered_at',
    ];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'action_params' => 'array',
            'enabled' => 'boolean',
            'last_evaluated_at' => 'datetime',
            'last_triggered_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(AutomationExecution::class);
    }
}

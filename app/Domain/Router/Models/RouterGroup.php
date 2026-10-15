<?php

declare(strict_types=1);

namespace App\Domain\Router\Models;

use App\Domain\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RouterGroup extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id', 'parent_id', 'name', 'type', 'color', 'description',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(RouterGroup::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(RouterGroup::class, 'parent_id');
    }

    public function routers(): BelongsToMany
    {
        return $this->belongsToMany(Router::class, 'router_group_members')
            ->withTimestamps();
    }
}

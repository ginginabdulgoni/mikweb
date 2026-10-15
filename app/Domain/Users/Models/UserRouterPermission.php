<?php

declare(strict_types=1);

namespace App\Domain\Users\Models;

use App\Domain\Router\Models\Router;
use App\Domain\Router\Models\RouterGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRouterPermission extends Model
{
    protected $fillable = [
        'user_id', 'router_id', 'router_group_id', 'abilities',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function routerGroup(): BelongsTo
    {
        return $this->belongsTo(RouterGroup::class);
    }

    public function grants(string $ability): bool
    {
        $abilities = $this->abilities ?? [];

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }
}

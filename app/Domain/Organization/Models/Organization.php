<?php

declare(strict_types=1);

namespace App\Domain\Organization\Models;

use App\Domain\Router\Models\Router;
use App\Domain\Router\Models\RouterGroup;
use App\Models\User;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasFactory, SoftDeletes;

    protected static string $factory = OrganizationFactory::class;

    protected $fillable = ['name', 'slug', 'description'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function routers(): HasMany
    {
        return $this->hasMany(Router::class);
    }

    public function routerGroups(): HasMany
    {
        return $this->hasMany(RouterGroup::class);
    }
}

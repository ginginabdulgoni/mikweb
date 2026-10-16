<?php

declare(strict_types=1);

namespace App\Domain\Customer\Models;

use App\Domain\Organization\Models\Organization;
use App\Domain\Router\Models\Router;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'router_id',
        'service_type',
        'username',
        'customer_name',
        'phone_number',
        'address',
        'monthly_bill',
        'due_day',
        'billing_status',
        'is_active',
        'is_isolated',
        'last_paid_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'monthly_bill' => 'decimal:2',
            'due_day' => 'integer',
            'is_active' => 'boolean',
            'is_isolated' => 'boolean',
            'last_paid_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function isOverdue(): bool
    {
        if ($this->billing_status === 'paid') {
            return false;
        }

        $todayDay = (int) now()->format('j');

        return $todayDay > $this->due_day;
    }
}

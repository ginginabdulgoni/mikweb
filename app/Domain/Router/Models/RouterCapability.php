<?php

declare(strict_types=1);

namespace App\Domain\Router\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouterCapability extends Model
{
    protected $fillable = [
        'router_id', 'routeros_major', 'supports_rest',
        'has_wifi_package', 'has_wireless_package', 'has_capsman', 'has_container',
        'features', 'packages', 'detected_at',
    ];

    protected function casts(): array
    {
        return [
            'supports_rest' => 'boolean',
            'has_wifi_package' => 'boolean',
            'has_wireless_package' => 'boolean',
            'has_capsman' => 'boolean',
            'has_container' => 'boolean',
            'features' => 'array',
            'packages' => 'array',
            'detected_at' => 'datetime',
        ];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    /** Whether a named feature is supported on this device. */
    public function supports(string $feature): bool
    {
        return (bool) data_get($this->features, $feature, false);
    }
}

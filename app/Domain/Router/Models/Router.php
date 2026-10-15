<?php

declare(strict_types=1);

namespace App\Domain\Router\Models;

use App\Domain\Organization\Models\Organization;
use App\Domain\Router\Enums\CredentialType;
use App\Domain\Router\Enums\RouterStatus;
use App\Domain\Router\Enums\Transport;
use App\Models\User;
use Database\Factories\RouterFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Router extends Model
{
    use HasFactory, SoftDeletes;

    protected static string $factory = RouterFactory::class;

    protected $fillable = [
        'organization_id', 'created_by', 'name', 'host',
        'api_port', 'api_ssl_port', 'ssh_port',
        'preferred_transport', 'use_tls', 'verify_tls',
        'snmp_enabled', 'snmp_version', 'snmp_port',
        'site', 'isp', 'location', 'latitude', 'longitude', 'tags',
        'identity', 'routeros_version', 'hardware_model', 'board_name',
        'serial_number', 'architecture',
        'status', 'last_seen_at', 'last_error',
        'monitoring_enabled', 'poll_interval_seconds', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'preferred_transport' => Transport::class,
            'status' => RouterStatus::class,
            'use_tls' => 'boolean',
            'verify_tls' => 'boolean',
            'snmp_enabled' => 'boolean',
            'monitoring_enabled' => 'boolean',
            'tags' => 'array',
            'latitude' => 'float',
            'longitude' => 'float',
            'last_seen_at' => 'datetime',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(RouterGroup::class, 'router_group_members')
            ->withTimestamps();
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(RouterCredential::class);
    }

    public function capability(): HasOne
    {
        return $this->hasOne(RouterCapability::class);
    }

    public function connectionLogs(): HasMany
    {
        return $this->hasMany(RouterConnectionLog::class);
    }

    public function commandLogs(): HasMany
    {
        return $this->hasMany(RouterCommandLog::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    public function credentialFor(CredentialType $type): ?RouterCredential
    {
        return $this->credentials->firstWhere('type', $type->value)
            ?? $this->credentials()->where('type', $type->value)->first();
    }

    /** The TCP port used for the router's preferred API transport. */
    public function apiPort(): int
    {
        return $this->preferred_transport === Transport::ApiSsl
            ? (int) $this->api_ssl_port
            : (int) $this->api_port;
    }

    public function isReachableStatus(): bool
    {
        return $this->status === RouterStatus::Online;
    }

    // ── Scopes ───────────────────────────────────────────────────────

    public function scopeForOrganization(Builder $query, int $organizationId): void
    {
        $query->where('organization_id', $organizationId);
    }

    public function scopeMonitored(Builder $query): void
    {
        $query->where('monitoring_enabled', true);
    }
}

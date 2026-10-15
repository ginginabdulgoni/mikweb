<?php

declare(strict_types=1);

namespace App\Domain\Router\Models;

use App\Domain\Router\Enums\CredentialType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-router credential set. Every secret column uses Laravel's "encrypted"
 * cast, so plaintext never touches the database. These attributes are also
 * declared $hidden so they can never leak through model serialization into an
 * Inertia prop, API response, or log line.
 */
class RouterCredential extends Model
{
    use HasFactory;

    protected $fillable = [
        'router_id', 'type', 'username', 'secret', 'private_key',
        'ssh_host_fingerprint',
        'snmp_security_level', 'snmp_auth_protocol', 'snmp_auth_password',
        'snmp_priv_protocol', 'snmp_priv_password',
    ];

    protected $hidden = [
        'secret', 'private_key', 'snmp_auth_password', 'snmp_priv_password',
    ];

    protected function casts(): array
    {
        return [
            'type' => CredentialType::class,
            'secret' => 'encrypted',
            'private_key' => 'encrypted',
            'snmp_auth_password' => 'encrypted',
            'snmp_priv_password' => 'encrypted',
        ];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }
}

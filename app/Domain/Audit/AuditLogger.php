<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Audit\Models\AuditLog;
use App\Support\SecretRedactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Writes immutable audit entries. Every config-changing action in MikWeb must
 * record one. Old/new value payloads are scrubbed of secrets first.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $meta
     */
    public static function record(
        string $action,
        ?Model $auditable = null,
        array $old = [],
        array $new = [],
        array $meta = [],
        ?int $routerId = null,
    ): AuditLog {
        $user = Auth::user();

        return AuditLog::create([
            'user_id' => $user?->id,
            'organization_id' => $user?->organization_id,
            'router_id' => $routerId,
            'action' => $action,
            'auditable_type' => $auditable !== null ? $auditable::class : null,
            'auditable_id' => $auditable?->getKey(),
            'ip_address' => Request::ip(),
            'user_agent' => (string) Request::userAgent(),
            'old_values' => $old === [] ? null : SecretRedactor::scrub($old),
            'new_values' => $new === [] ? null : SecretRedactor::scrub($new),
            'meta' => $meta === [] ? null : SecretRedactor::scrub($meta),
            'created_at' => now(),
        ]);
    }
}

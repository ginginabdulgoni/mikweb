<?php

declare(strict_types=1);

namespace App\Http\Requests\Router;

use App\Domain\Router\Enums\Transport;
use App\Domain\Router\Models\Router;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRouterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Router::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $orgId = $this->user()->organization_id;

        return [
            'name' => ['required', 'string', 'max:120'],
            'host' => [
                'required', 'string', 'max:255',
                // Host must be unique within the organisation.
                Rule::unique('routers', 'host')
                    ->where(fn ($q) => $q->where('organization_id', $orgId)->whereNull('deleted_at')),
            ],
            'preferred_transport' => ['required', Rule::enum(Transport::class)],
            'api_port' => ['integer', 'between:1,65535'],
            'api_ssl_port' => ['integer', 'between:1,65535'],
            'ssh_port' => ['integer', 'between:1,65535'],
            'use_tls' => ['boolean'],
            'verify_tls' => ['boolean'],
            'snmp_enabled' => ['boolean'],
            'snmp_version' => ['nullable', Rule::in(['v2c', 'v3'])],
            'snmp_port' => ['integer', 'between:1,65535'],
            'site' => ['nullable', 'string', 'max:120'],
            'isp' => ['nullable', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:40'],
            'poll_interval_seconds' => ['integer', 'between:5,3600'],
            'monitoring_enabled' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],

            // API credential (optional at create; required before a real connect)
            'credential.username' => ['nullable', 'string', 'max:120'],
            'credential.password' => ['nullable', 'string', 'max:255'],
            'snmp_community' => ['nullable', 'string', 'max:255'],
        ];
    }
}

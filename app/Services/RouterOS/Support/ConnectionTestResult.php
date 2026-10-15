<?php

declare(strict_types=1);

namespace App\Services\RouterOS\Support;

/**
 * Outcome of a Test Connection attempt. Safe to serialize to the UI — carries
 * no secrets.
 */
final class ConnectionTestResult
{
    /**
     * @param  array<string, mixed>  $info  discovered identity/version/model, etc.
     */
    public function __construct(
        public readonly bool $success,
        public readonly int $durationMs,
        public readonly string $message,
        public readonly array $info = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'duration_ms' => $this->durationMs,
            'message' => $this->message,
            'info' => $this->info,
        ];
    }
}

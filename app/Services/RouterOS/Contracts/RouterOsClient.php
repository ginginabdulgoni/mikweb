<?php

declare(strict_types=1);

namespace App\Services\RouterOS\Contracts;

use App\Services\RouterOS\Support\ConnectionConfig;

/**
 * Transport-agnostic contract for talking to a single RouterOS device.
 *
 * Implementations: LibraryRouterOsClient (real API protocol) and
 * MockRouterOsClient (in-memory, for dev/tests). Higher layers depend only on
 * this interface so the backend never couples to a specific wire library.
 */
interface RouterOsClient
{
    public function configure(ConnectionConfig $config): static;

    /** Open the session. Throws RouterOsConnectionException on failure. */
    public function connect(): void;

    public function disconnect(): void;

    public function isConnected(): bool;

    /**
     * Read from a print-style menu, e.g. "/system/resource/print" or
     * "/interface/print". $filters are RouterOS "?" query conditions
     * (["disabled" => "false"]).
     *
     * @param  array<string, scalar>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function query(string $command, array $filters = []): array;

    /**
     * Execute a write/action command, e.g. "/ip/address/add". $params become
     * "=" attributes. Returns whatever rows RouterOS replies with.
     *
     * @param  array<string, scalar>  $params
     * @return array<int, array<string, mixed>>
     */
    public function execute(string $command, array $params = []): array;
}

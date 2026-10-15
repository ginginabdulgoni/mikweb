<?php

declare(strict_types=1);

namespace App\Services\RouterOS\Adapters;

use App\Services\RouterOS\Contracts\RouterOsClient;
use App\Services\RouterOS\Exceptions\RouterOsCommandException;
use App\Services\RouterOS\Exceptions\RouterOsConnectionException;
use App\Services\RouterOS\Support\ConnectionConfig;
use RouterOS\Client as RosClient;
use RouterOS\Exceptions\BadCredentialsException;
use RouterOS\Exceptions\ConnectException;
use RouterOS\Query;
use Throwable;

/**
 * Real RouterOS API client backed by evilfreelancer/routeros-api-php.
 * Speaks the native binary API protocol (and API-SSL). Translates the
 * library's exceptions and quirky trap-in-response format into MikWeb's
 * typed exceptions and a clean list-of-rows result.
 */
class LibraryRouterOsClient implements RouterOsClient
{
    private ?ConnectionConfig $config = null;

    private ?RosClient $client = null;

    public function configure(ConnectionConfig $config): static
    {
        $this->config = $config;

        return $this;
    }

    public function connect(): void
    {
        $config = $this->requireConfig();

        try {
            // autoConnect = false so we control (and time) the handshake.
            $this->client = new RosClient($config->toLibraryConfig(), false);
            $this->client->connect();
        } catch (BadCredentialsException $e) {
            throw new RouterOsConnectionException(
                'Authentication rejected by the router (check username/password).',
                previous: $e,
            );
        } catch (ConnectException $e) {
            throw new RouterOsConnectionException(
                $this->humanizeConnectError($e->getMessage()),
                previous: $e,
            );
        } catch (Throwable $e) {
            throw new RouterOsConnectionException(
                'Unable to establish a RouterOS API session: '.$this->safeMessage($e),
                previous: $e,
            );
        }
    }

    public function disconnect(): void
    {
        $this->client = null;
    }

    public function isConnected(): bool
    {
        return $this->client !== null;
    }

    public function query(string $command, array $filters = []): array
    {
        $query = new Query($command);

        foreach ($filters as $key => $value) {
            $query->where($key, $this->stringify($value));
        }

        return $this->run($query);
    }

    public function execute(string $command, array $params = []): array
    {
        $query = new Query($command);

        foreach ($params as $key => $value) {
            $query->equal($key, $this->stringify($value));
        }

        return $this->run($query);
    }

    private function run(Query $query): array
    {
        if ($this->client === null) {
            $this->connect();
        }

        try {
            /** @var array<array-key, mixed> $raw */
            $raw = $this->client->query($query)->read();
        } catch (Throwable $e) {
            throw new RouterOsCommandException(
                'RouterOS rejected the request: '.$this->safeMessage($e),
                previous: $e,
            );
        }

        return $this->normalize($raw);
    }

    /**
     * The library returns a trap error inside result['after']['message'] and
     * numeric keys for actual rows. Split the two apart here.
     *
     * @param  array<array-key, mixed>  $raw
     * @return array<int, array<string, mixed>>
     */
    private function normalize(array $raw): array
    {
        $after = $raw['after'] ?? null;

        if (is_array($after) && isset($after['message'])) {
            throw new RouterOsCommandException((string) $after['message']);
        }

        $rows = [];
        foreach ($raw as $key => $value) {
            if (is_int($key) && is_array($value)) {
                $rows[] = $value;
            }
        }

        // Pure action responses (e.g. /ip/address/add) carry no rows but may
        // return metadata such as the new ".id" in 'after'.
        if ($rows === [] && is_array($after) && $after !== []) {
            $rows[] = $after;
        }

        return $this->sanitizeUtf8($rows);
    }

    /**
     * Recursively ensure all string values and keys are valid UTF-8.
     * Prevents JsonResponse / json_encode crashes on binary or malformed characters from RouterOS.
     */
    private function sanitizeUtf8(mixed $value): mixed
    {
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $k => $v) {
                $cleanKey = is_string($k) ? mb_convert_encoding($k, 'UTF-8', 'UTF-8') : $k;
                $sanitized[$cleanKey] = $this->sanitizeUtf8($v);
            }

            return $sanitized;
        }

        if (is_string($value)) {
            return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        return $value;
    }

    private function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    private function humanizeConnectError(string $message): string
    {
        $message = strtolower($message);

        return match (true) {
            str_contains($message, 'timed out'), str_contains($message, 'timeout') => 'Connection to the router timed out (host unreachable or API port filtered).',
            str_contains($message, 'refused') => 'The router refused the connection (API service disabled or wrong port).',
            str_contains($message, 'ssl'), str_contains($message, 'tls') => 'TLS handshake with the router failed (certificate or cipher mismatch).',
            default => 'Could not reach the router on the configured API port.',
        };
    }

    private function safeMessage(Throwable $e): string
    {
        // Never leak credentials that a library might echo back in a message.
        $message = $e->getMessage();

        return $message === '' ? $e::class : $message;
    }

    private function requireConfig(): ConnectionConfig
    {
        if ($this->config === null) {
            throw new RouterOsConnectionException('RouterOS client used before configure().');
        }

        return $this->config;
    }
}

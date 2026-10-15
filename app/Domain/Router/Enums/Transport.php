<?php

declare(strict_types=1);

namespace App\Domain\Router\Enums;

/**
 * How MikWeb talks to a given RouterOS device.
 */
enum Transport: string
{
    case Api = 'api';          // RouterOS API (plaintext, default 8728)
    case ApiSsl = 'api-ssl';   // RouterOS API over TLS (default 8729)
    case Ssh = 'ssh';          // SSH CLI gateway
    case Rest = 'rest';        // RouterOS v7 REST API (https)

    public function isApi(): bool
    {
        return $this === self::Api || $this === self::ApiSsl;
    }

    public function usesTls(): bool
    {
        return $this === self::ApiSsl || $this === self::Rest;
    }
}

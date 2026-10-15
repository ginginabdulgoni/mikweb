<?php

declare(strict_types=1);

namespace App\Services\RouterOS\Exceptions;

/**
 * Raised when MikWeb cannot establish or maintain a session with the device
 * (unreachable host, TLS failure, bad credentials, timeout).
 */
class RouterOsConnectionException extends RouterOsException {}

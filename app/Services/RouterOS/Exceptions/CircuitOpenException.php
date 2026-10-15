<?php

declare(strict_types=1);

namespace App\Services\RouterOS\Exceptions;

/**
 * Raised when the per-router circuit breaker is open: recent calls failed
 * repeatedly, so MikWeb fails fast instead of hammering a device that is
 * almost certainly still down.
 */
class CircuitOpenException extends RouterOsConnectionException {}

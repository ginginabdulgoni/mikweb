<?php

declare(strict_types=1);

namespace App\Services\RouterOS\Exceptions;

/**
 * Raised when a command reached the device but RouterOS rejected it
 * (bad syntax, missing item, permission denied on the device side).
 */
class RouterOsCommandException extends RouterOsException {}

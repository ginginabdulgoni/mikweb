<?php

declare(strict_types=1);

namespace App\Services\RouterOS\Exceptions;

use RuntimeException;

/**
 * Base for all RouterOS engine failures. Messages are safe to surface to the
 * UI; secrets are never embedded here.
 */
class RouterOsException extends RuntimeException {}

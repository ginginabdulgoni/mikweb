<?php

declare(strict_types=1);

namespace App\Domain\Router\Enums;

enum RouterStatus: string
{
    case Online = 'online';
    case Offline = 'offline';
    case Unreachable = 'unreachable';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::Offline => 'Offline',
            self::Unreachable => 'Unreachable',
            self::Unknown => 'Unknown',
        };
    }

    /** Tailwind-friendly colour token used by the UI status indicator. */
    public function color(): string
    {
        return match ($this) {
            self::Online => 'emerald',
            self::Offline => 'zinc',
            self::Unreachable => 'red',
            self::Unknown => 'amber',
        };
    }
}

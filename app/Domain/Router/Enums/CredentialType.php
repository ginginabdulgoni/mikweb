<?php

declare(strict_types=1);

namespace App\Domain\Router\Enums;

enum CredentialType: string
{
    case Api = 'api';
    case Ssh = 'ssh';
    case Snmp = 'snmp';
}

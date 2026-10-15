<?php

declare(strict_types=1);

namespace App\Support\Access;

/**
 * Canonical catalogue of MikWeb abilities and the default role → ability map.
 * Used by the seeder, policies, and per-router permission grants so there is a
 * single source of truth for "what can be done".
 *
 * Ability naming: "<feature>.<operation>". Feature-specific abilities beyond
 * routers (ppp.*, firewall.*, …) are declared here as the matching modules land.
 */
final class Permissions
{
    // Router management
    public const ROUTER_VIEW = 'router.view';

    public const ROUTER_MANAGE = 'router.manage';     // create/update/delete

    public const ROUTER_TEST = 'router.test';         // test connection / sync info

    public const ROUTER_CREDENTIALS = 'router.credentials'; // view/set credentials

    // Groups
    public const GROUP_VIEW = 'group.view';

    public const GROUP_MANAGE = 'group.manage';

    // RouterOS modules (per-device, enforced with router scope)
    public const INTERFACE_VIEW = 'interface.view';

    public const INTERFACE_MANAGE = 'interface.manage';

    public const IP_VIEW = 'ip.view';

    public const IP_MANAGE = 'ip.manage';

    public const PPP_VIEW = 'ppp.view';

    public const PPP_MANAGE = 'ppp.manage';

    public const HOTSPOT_VIEW = 'hotspot.view';

    public const HOTSPOT_MANAGE = 'hotspot.manage';

    public const QUEUE_VIEW = 'queue.view';

    public const QUEUE_MANAGE = 'queue.manage';

    public const FIREWALL_VIEW = 'firewall.view';

    public const FIREWALL_MANAGE = 'firewall.manage';

    public const SYSTEM_VIEW = 'system.view';

    public const SYSTEM_MANAGE = 'system.manage';

    public const TOOLS_USE = 'tools.use';

    public const BACKUP_VIEW = 'backup.view';

    public const BACKUP_MANAGE = 'backup.manage';

    public const AUTOMATION_VIEW = 'automation.view';

    public const AUTOMATION_MANAGE = 'automation.manage';

    // Monitoring & audit (read)
    public const MONITORING_VIEW = 'monitoring.view';

    public const AUDIT_VIEW = 'audit.view';

    // Administration
    public const USER_MANAGE = 'user.manage';

    public const ORG_MANAGE = 'org.manage';

    public const ROLE_SUPER_ADMIN = 'super-admin';

    public const ROLE_ORG_ADMIN = 'org-admin';

    public const ROLE_NETWORK_ADMIN = 'network-admin';

    public const ROLE_NETWORK_OPERATOR = 'network-operator';

    public const ROLE_MONITORING = 'monitoring-user';

    public const ROLE_READ_ONLY = 'read-only';

    /** @return array<int, string> every ability MikWeb knows about */
    public static function all(): array
    {
        return [
            self::ROUTER_VIEW, self::ROUTER_MANAGE, self::ROUTER_TEST, self::ROUTER_CREDENTIALS,
            self::GROUP_VIEW, self::GROUP_MANAGE,
            self::INTERFACE_VIEW, self::INTERFACE_MANAGE,
            self::IP_VIEW, self::IP_MANAGE,
            self::PPP_VIEW, self::PPP_MANAGE,
            self::HOTSPOT_VIEW, self::HOTSPOT_MANAGE,
            self::QUEUE_VIEW, self::QUEUE_MANAGE,
            self::FIREWALL_VIEW, self::FIREWALL_MANAGE,
            self::SYSTEM_VIEW, self::SYSTEM_MANAGE,
            self::TOOLS_USE,
            self::BACKUP_VIEW, self::BACKUP_MANAGE,
            self::AUTOMATION_VIEW, self::AUTOMATION_MANAGE,
            self::MONITORING_VIEW, self::AUDIT_VIEW,
            self::USER_MANAGE, self::ORG_MANAGE,
        ];
    }

    /** Module view/manage abilities grouped — handy for seeding roles. */
    private const MODULE_VIEW = [
        self::INTERFACE_VIEW, self::IP_VIEW, self::PPP_VIEW, self::HOTSPOT_VIEW, self::QUEUE_VIEW, self::FIREWALL_VIEW,
        self::SYSTEM_VIEW, self::BACKUP_VIEW, self::AUTOMATION_VIEW,
    ];

    private const MODULE_MANAGE = [
        self::INTERFACE_MANAGE, self::IP_MANAGE, self::PPP_MANAGE, self::HOTSPOT_MANAGE, self::QUEUE_MANAGE, self::FIREWALL_MANAGE,
        self::SYSTEM_MANAGE, self::BACKUP_MANAGE, self::AUTOMATION_MANAGE, self::TOOLS_USE,
    ];

    /**
     * Default abilities granted to each role. super-admin is handled as a
     * wildcard in the policy layer and intentionally omitted here.
     *
     * @return array<string, array<int, string>>
     */
    public static function roleMatrix(): array
    {
        return [
            self::ROLE_ORG_ADMIN => self::all(),
            self::ROLE_NETWORK_ADMIN => array_merge([
                self::ROUTER_VIEW, self::ROUTER_MANAGE, self::ROUTER_TEST, self::ROUTER_CREDENTIALS,
                self::GROUP_VIEW, self::GROUP_MANAGE, self::MONITORING_VIEW, self::AUDIT_VIEW,
            ], self::MODULE_VIEW, self::MODULE_MANAGE),
            self::ROLE_NETWORK_OPERATOR => array_merge([
                self::ROUTER_VIEW, self::ROUTER_TEST, self::GROUP_VIEW, self::MONITORING_VIEW,
                // Operators manage PPPoE, Hotspot, Queues + run tools.
                self::PPP_MANAGE, self::HOTSPOT_MANAGE, self::QUEUE_MANAGE, self::TOOLS_USE,
            ], self::MODULE_VIEW),
            self::ROLE_MONITORING => array_merge([
                self::ROUTER_VIEW, self::GROUP_VIEW, self::MONITORING_VIEW,
            ], self::MODULE_VIEW),
            self::ROLE_READ_ONLY => [
                self::ROUTER_VIEW, self::GROUP_VIEW, self::INTERFACE_VIEW, self::IP_VIEW,
            ],
        ];
    }

    /** @return array<int, string> */
    public static function roles(): array
    {
        return [
            self::ROLE_SUPER_ADMIN, self::ROLE_ORG_ADMIN, self::ROLE_NETWORK_ADMIN,
            self::ROLE_NETWORK_OPERATOR, self::ROLE_MONITORING, self::ROLE_READ_ONLY,
        ];
    }
}
